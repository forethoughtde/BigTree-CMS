<?
	/*
		Class: BigTreeCloudStorage
			A cloud storage interface class that provides service agnostic calls on top of various cloud storage platforms.
	*/

	require_once(BigTree::path("inc/bigtree/apis/_oauth.base.php"));
	class BigTreeCloudStorage extends BigTreeOAuthAPIBase {

		// These are only applicable to Google Cloud Storage
		var $AuthorizeURL = "https://accounts.google.com/o/oauth2/auth";
		var $EndpointURL = "https://www.googleapis.com/storage/v1beta2/";
		var $OAuthVersion = "1.0";
		var $RequestType = "header";
		var $Scope = "https://www.googleapis.com/auth/devstorage.full_control";
		var $TokenURL = "https://accounts.google.com/o/oauth2/token";

		/*
			Constructor:
				Retrieves the current desired service and settings.

			Parameters:
				service - The service to use (amazon, rackspace, google) — if this is left empty it will use $this->Settings["service"] which can be set and auto saves.
		*/
		
		function __construct($service = false) {
			parent::__construct("bigtree-internal-cloud-storage","Cloud Storage","org.bigtreecms.api.cloud-storage",false);
			$this->Service = $service ? $service : $this->Settings["service"];

			// Set OAuth Return URL for Google Cloud Storage
			$this->ReturnURL = ADMIN_ROOT."developer/cloud-storage/google/return/";

			// Retrieve a fresh token for Rackspace Cloud Files
			if ($this->Service == "rackspace") {
				if (!isset($this->Settings["rackspace"]["token_expiration"]) || $this->Settings["rackspace"]["token_expiration"] < time()) {
					$this->_getRackspaceToken();
				}
				$this->RackspaceAPIEndpoint = $this->Settings["rackspace"]["endpoints"][$this->Settings["rackspace"]["region"]];
			}
			
		}

		/*
			Function: _getRackspaceToken
				Gets a new access token for the Rackspace Cloud Files API.
		*/

		function _getRackspaceToken() {
			$j = json_decode(BigTree::cURL("https://identity.api.rackspacecloud.com/v2.0/tokens",json_encode(array(
				"auth" => array(
					"RAX-KSKEY:apiKeyCredentials" => array(
						"username" => $this->Settings["rackspace"]["username"],
						"apiKey" => $this->Settings["rackspace"]["api_key"]
					)
				)
			)),array(CURLOPT_POST => true,CURLOPT_HTTPHEADER => array("Content-Type: application/json"))));
			
			if (isset($j->access->token)) {
				$this->Settings["rackspace"]["token"] = $j->access->token->id;
				$this->Settings["rackspace"]["token_expiration"] = strtotime($j->access->token->expires);
				$this->Settings["rackspace"]["endpoints"] = array();
				// Get API endpoints
				foreach ($j->access->serviceCatalog as $service) {
					if ($service->name == "cloudFiles") {
						foreach ($service->endpoints as $endpoint) {
							$this->Settings["rackspace"]["endpoints"][$endpoint->region] = (string)$endpoint->publicURL;
						}
					}
				}
				return true;
			}
		}

		/*
			Function: _hash
				Used for HMAC hashing internally.

			Parameters:
				secret - Secret used to hash.
				string - String to hash.

			Returns:
				Hashed string.
		*/

		private function _hash($secret,$string) {
			if (extension_loaded("hash")) {
				return base64_encode(hash_hmac('sha1',$string,$secret,true));
			}
			return base64_encode(pack('H*',sha1((str_pad($secret,64,chr(0x00)) ^ (str_repeat(chr(0x5c),64))).pack('H*',sha1((str_pad($secret,64,chr(0x00)) ^ (str_repeat(chr(0x36), 64))).$string)))));
		}

		/*
			Function: _setAmazonError
				Parses an Amazon response for the error message and sets $this->Error
		*/

		private function _setAmazonError($xml) {
			$xml = simplexml_load_string($xml);
			$this->Errors[] = array("message" => (string)$xml->Message, "code" => (string)$xml->Code);
		}

		/*
			Function: copyFile
				Copies a file from one container/location to another container/location.

			Parameters:
				source_container - The container the file is stored in.
				source_pointer - The full file path inside the container.
				destination_container - The container to copy the source file to.
				destination_pointer - The full file path to store the copied file
				access - Access control level for the new file: "private" for no outside access (default), "read" for public read, "write" for public read/write

			Returns:
				true if successful.
		*/

		function copyFile($source_container,$source_pointer,$destination_container,$destination_pointer,$access = "private") {
			// Amazon S3
			if ($this->Service == "amazon") {
				// Get the Amazon code for the access level
				$access_levels = array("private" => "private","read" => "public-read","write" => "public-read-write");
				$acl = $access_levels[$access];
				if (!$acl) {
					return false;
				}

				$response = $this->callAmazonS3("PUT",$destination_container,$destination_pointer,array(),array("Content-Length" => "0"),array(
					"x-amz-acl" => $acl,
					"x-amz-copy-source" => "/".$source_container."/".rawurlencode($source_pointer)
				));

				if ($this->HTTPResponseCode != "200") {
					return false;
				}
				return true;
			// Rackspace Cloud Files
			} elseif ($this->Service == "rackspace") {

			// Google Cloud Storage
			} elseif ($this->Service == "google") {
				$response = $this->call("b/$source_container/o/$source_pointer/copyTo/b/$destination_container/o/$destination_pointer","{}","POST");
				if (isset($response->id)) {
					// Set the access control level if it's publicly accessible
					if ($access == "read") {
						$this->call("b/$destination_container/o/$destination_pointer/acl",json_encode(array("entity" => "allUsers","role" => "READER")),"POST");
					} elseif ($access == "write") {
						$this->call("b/$destination_container/o/$destination_pointer/acl",json_encode(array("entity" => "allUsers","role" => "OWNER")),"POST");
					}
					return true;
				} else {
					return false;
				}
			} else {
				return false;
			}
		}

		/*
			Function: createContainer
				Creates a new container/bucket.

			Parameters:
				name - Container name (keep in mind this must be unique among all other containers)
				access - Access control level: "private" for no outside access (default), "read" for public read, "write" for public read/write
			
			Returns:
				true if successful.
		*/

		function createContainer($name,$access = "private") {
			// Amazon S3
			if ($this->Service == "amazon") {
				// Get the Amazon code for the access level
				$access_levels = array("private" => "private","read" => "public-read","write" => "public-read-write");
				$acl = $access_levels[$access];
				if (!$acl) {
					return false;
				}

				$response = $this->callAmazonS3("PUT",$name,"",array(),array(),array("x-amz-acl" => $acl));
				if (!$response) {
					return true;
				}
				$this->_setAmazonError($response);
				return false;
			// Rackspace Cloud Files
			} elseif ($this->Service == "rackspace") {
				global $bigtree;
				$response = $this->callRackspace($name,"",array(CURLOPT_PUT => true));
				if ($bigtree["last_curl_response_code"] == 201) {
					return true;
				} else {
					return false;
				}
			// Google Cloud Storage
			} elseif ($this->Service == "google") {
				$response = $this->call("b?project=".$this->Settings["project"],json_encode(array("name" => $name)),"POST");
				if (isset($response->id)) {
					return true;
				} else {
					return false;
				}
			} else {
				return false;
			}
		}

		/*
			Function: createFile
				Creates a new file in the given container.

			Parameters:
				contents - What to write to the file.
				container - Container name.
				pointer - The full file path inside the container.
				access - Access control level: "private" for no outside access (default), "read" for public read, "write" for public read/write
				type - MIME type (defaults to "text/plain")
			
			Returns:
				true if successful.
		*/

		function createFile($contents,$container,$pointer,$access = "private",$type = "text/plain") {
			// Amazon S3
			if ($this->Service == "amazon") {
				// Get the Amazon code for the access level
				$access_levels = array("private" => "private","read" => "public-read","write" => "public-read-write");
				$acl = $access_levels[$access];
				if (!$acl) {
					return false;
				}

				$response = $this->callAmazonS3("PUT",$container,$pointer,array(),array(
					"Content-Type" => $type,
					"Content-Length" => strlen($contents)
				),array("x-amz-acl" => $acl),$contents);

				if (!$response) {
					return true;
				}
				$this->_setAmazonError($response);
				return false;
			// Rackspace Cloud Files
			} elseif ($this->Service == "rackspace") {

			// Google Cloud Storage
			} elseif ($this->Service == "google") {
				$response = json_decode(BigTree::cURL("https://www.googleapis.com/upload/storage/v1beta2/b/$container/o?name=$pointer&uploadType=media",$contents,array(CURLOPT_POST => true, CURLOPT_HTTPHEADER => array("Content-Type: $type","Content-Length: ".strlen($contents),"Authorization: Bearer ".$this->Settings["token"]))));
				if (isset($response->id)) {
					// Set the access control level if it's publicly accessible
					if ($access == "read") {
						$this->call("b/$container/o/$pointer/acl",json_encode(array("entity" => "allUsers","role" => "READER")),"POST");
					} elseif ($access == "write") {
						$this->call("b/$container/o/$pointer/acl",json_encode(array("entity" => "allUsers","role" => "OWNER")),"POST");
					}
					return true;
				} else {
					foreach ($response->error->errors as $error) {
						$this->Errors[] = $error;
					}
					return false;
				}
			} else {
				return false;
			}
		}

		/*
			Function: createFolder
				Creates a new folder in the given container.

			Parameters:
				container - Container name.
				pointer - The full folder path inside the container.
			
			Returns:
				true if successful.
		*/

		function createFolder($container,$pointer) {
			return $this->createFile("",$container,rtrim($pointer,"/")."/");
		}

		/*
			Function: deleteContainer
				Deletes a container/bucket.
				Containers must be empty to be deleted.

			Parameters:
				container - Container to delete.
			
			Returns:
				true if successful.
		*/

		function deleteContainer($container) {
			// Amazon S3
			if ($this->Service == "amazon") {
				$response = $this->callAmazonS3("DELETE",$container);
				if (!$response) {
					return true;
				}
				$this->_setAmazonError($response);
				return false;
			// Rackspace Cloud Files
			} elseif ($this->Service == "rackspace") {
				global $bigtree;
				$response = $this->callRackspace($container,"",array(CURLOPT_CUSTOMREQUEST => "DELETE"));
				if ($bigtree["last_curl_response_code"] == 204) {
					return true;
				} elseif ($bigtree["last_curl_response_code"] == 404) {
					$this->Errors[] = array("message" => "Container was not found.");
				} elseif ($bigtree["last_curl_response_code"] == 409) {
					$this->Errors[] = array("message" => "Container could not be deleted because it is not empty.");	
				}
			// Google Cloud Storage
			} elseif ($this->Service == "google") {
				$error_count = count($this->Errors);
				$response = $this->call("b/$container",false,"DELETE");
				if (count($this->Errors) > $error_count) {
					return false;
				}
				return true;
			} else {
				return false;
			}
		}

		/*
			Function: deleteFile
				Deletes a file from the given container.

			Parameters:
				container - The container the file is stored in.
				pointer - The full file path inside the container.

			Returns:
				true if successful
		*/

		function deleteFile($container,$pointer) {
			// Amazon S3
			if ($this->Service == "amazon") {
				$response = $this->callAmazonS3("DELETE",$container,$pointer);
				if ($this->HTTPResponseCode != "204") {
					return false;
				}
				return true;
			// Rackspace Cloud Files
			} elseif ($this->Service == "rackspace") {

			// Google Cloud Storage
			} elseif ($this->Service == "google") {
				$error_count = count($this->Errors);
				$response = $this->call("b/$container/o/$pointer",false,"DELETE");
				if (count($this->Errors) > $error_count) {
					return false;
				}
				return true;
			} else {
				return false;
			}
		}

		/*
			Function: getAuthenticatedFileURL
				Returns a URL that is valid for a limited amount of time to a private file.

			Parameters:
				container - The container the file is in.
				pointer - The full file path inside the container.
				expires - The number of seconds before this URL will expire.

			Returns:
				A URL.
		*/

		function getAuthenticatedFileURL($container,$pointer,$expires) {
			$expires += time();

			// Amazon S3
			if ($this->Service == "amazon") {
				$pointer = str_replace(array('%2F', '%2B'),array('/', '+'),rawurlencode($pointer));
				return "http://s3.amazonaws.com/".$container."/".$pointer."?AWSAccessKeyId=".$this->AmazonKey."&Expires=$expires&Signature=".$this->_hash($this->AmazonSecret,"GET\n\n\n$expires\n/$container/$pointer");
			// Rackspace Cloud Files
			} elseif ($this->Service == "rackspace") {

			// Google Cloud Storage
			} elseif ($this->Service == "google") {
				if (!function_exists('openssl_x509_read')) {
					throw new Exception("PHP's OpenSSL extension is required to use authenticated URLs with Google Cloud Storage.");
				}
				if (!$this->Settings["private_key"] || !$this->Settings["certificate_email"]) {
					throw new Exception("You must upload your Google Cloud Storage private key and set your Certificate Email Address to use authenticated URLs.");
				}
				// Google's default password for these is "notasecret"
				$certificates = array();
				if (!openssl_pkcs12_read(file_get_contents($this->Settings["private_key"]),$certificates,"notasecret")) {
	  				throw new Exception("Unable to parse Google Cloud Storage private key file:".openssl_error_string());
				}
				$private_key = openssl_pkey_get_private($certificates["pkey"]);
				// Sign the string
				openssl_sign("GET\n\n\n$expires\n/$container/$pointer",$signature,$private_key,"sha256");

				return "http://storage.googleapis.com/$container/$pointer?GoogleAccessId=".$this->Settings["certificate_email"]."&Expires=$expires&Signature=".urlencode(base64_encode($signature));
			} else {
				return false;
			}
		}

		/*
			Function: getContainer
				Lists the contents of a container/bucket.

			Parameters:
				container - The name of the container.

			Returns:
				An array of the contents of the container.
		*/

		function getContainer($container) {
			$tree = array("folders" => array(),"files" => array());
			$flat = array();
			
			// Amazon S3
			if ($this->Service == "amazon") {
				$xml = simplexml_load_string($this->callAmazonS3("GET",$container));
				foreach ($xml->Contents as $item) {
					$flat[] = $raw_item = array(
						"name" => (string)$item->Key,
						"path" => (string)$item->Key,
						"updated_at" => date("Y-m-d H:i:s",strtotime($item->LastModified)),
						"etag" => (string)$item->ETag,
						"size" => (int)$item->Size,
						"owner" => array(
							"name" => (string)$item->Owner->DisplayName,
							"id" => (string)$item->Owner->ID
						),
						"storage_class" => (string)$item->StorageClass
					);
				}
			// Rackspace Cloud Files
			} elseif ($this->Service == "rackspace") {

			// Google Cloud Storage
			} elseif ($this->Service == "google") {
				$response = $this->call("b/$container/o");
				foreach ($response->items as $item) {
					$flat[] = $raw_item = array(
						"name" => (string)$item->name,
						"path" => (string)$item->name,
						"updated_at" => date("Y-m-d H:i:s",strtotime($item->updated)),
						"etag" => (string)$item->etag,
						"size" => (int)$item->size,
						"owner" => array(
							"name" => (string)$item->owner->entity,
							"id" => (string)$item->owner->entityId
						)
					);
				}
			} else {
				return false;
			}

			foreach ($flat as $raw_item) {
				$keys = explode("/",$raw_item["name"]);
				// We're going to use by reference vars to figure out which folder to place this in
				if (count($keys) > 1) {
					$folder = &$tree;
					for ($i = 0; $i < count($keys); $i++) {
						// Last part of the key and also has a . so we know it's actually a file
						if ($i == count($keys) - 1 && strpos($keys[$i],".") !== false) {
							$raw_item["name"] = $keys[$i];
							$folder["files"][] = $raw_item;
						} else {
							if ($keys[$i]) {
								if (!isset($folder["folders"][$keys[$i]])) {
									$folder["folders"][$keys[$i]] = array("folders" => array(),"files" => array());
								}
								$folder = &$folder["folders"][$keys[$i]];
							}
						}
					}
				} else {
					$tree["files"][] = $raw_item;
				}
			}

			return array("flat" => $flat,"tree" => $tree);
		}

		/*
			Function: getFile
				Returns a file from the given container.

			Parameters:
				container - The container the file is stored in.
				pointer - The full file path inside the container.

			Returns:
				A binary stream of data or false if the file is not found or not allowed.
		*/

		function getFile($container,$pointer) {
			// Amazon S3
			if ($this->Service == "amazon") {
				$response = $this->callAmazonS3("GET",$container,$pointer);
				if ($this->HTTPResponseCode != "200") {
					return false;
				}
				return $response;
			// Rackspace Cloud Files
			} elseif ($this->Service == "rackspace") {

			// Google Cloud Storage
			} elseif ($this->Service == "google") {
				// We do a manual call because the "call" method always assumes the response is JSON.
				return BigTree::cURL("https://storage.googleapis.com/$container/$pointer",false,array(CURLOPT_HTTPHEADER => array("Authorization: Bearer ".$this->Settings["token"])));
			} else {
				return false;
			}
		}

		/*
			Function: listContainers
				Lists containers/buckets that are available in this cloud account.

			Returns:
				An array of container names.
		*/

		function listContainers() {
			$containers = array();
			// Amazon S3
			if ($this->Service == "amazon") {
				$xml = simplexml_load_string($this->callAmazonS3());
				foreach ($xml->Buckets->Bucket as $bucket) {
					$containers[] = array(
						"name" => (string)$bucket->Name,
						"created_at" => date("Y-m-d H:i:s",strtotime($bucket->CreationDate))
					);
				}
			// Rackspace Cloud Files
			} elseif ($this->Service == "rackspace") {
				$response = $this->callRackspace();
				foreach ($response as $item) {
					$containers[] = array("name" => (string)$item->name);
				}
			// Google Cloud Storage
			} elseif ($this->Service == "google") {
				$resposne = $this->call("b",array("project" => $this->Settings["project"]));
				foreach ($resposne->items as $item) {
					$containers[] = array(
						"name" => (string)$item->name,
						"created_at" => date("Y-m-d H:i:s",strtotime($item->timeCreated)),
						"location" => (string)$item->location,
						"storage_class" => (string)$item->storageClass
					);
				}
			} else {
				return false;
			}

			return $containers;
		}

		/*
			Function: updateContainerAccessLevel
				Updates the access level of a container.
				For Amazon S3, the container will be created if it doesn't exist.

			Parameters:
				container - Container name (keep in mind this must be unique among all other containers)
				access - Access control level: "private" for no outside access (default), "read" for public read, "write" for public read/write
			
			Returns:
				true if successful.
		*/

		function updateContainerAccessLevel($container,$level) {
			// Amazon S3
			if ($this->Service == "amazon") {
				return $this->createContainer($container,$level);
			// Rackspace Cloud Files
			} elseif ($this->Service == "rackspace") {

			// Google Cloud Storage
			} elseif ($this->Service == "google") {

			} else {
				return false;
			}
		}

		/*
			Function: uploadFile
				Creates a new file in the given container.

			Parameters:
				file - The file to upload.
				container - Container name.
				pointer - The full file path inside the container (if left empty the file's current name will be used and the root of the bucket)
				access - Access control level: "private" for no outside access (default), "read" for public read, "write" for public read/write
				type - MIME type (defaults to "text/plain")
			
			Returns:
				true if successful.
		*/

		function uploadFile($file,$container,$pointer = false,$access = "private") {
			// MIME Types
			$exts = array(
				"jpg" => "image/jpeg", "jpeg" => "image/jpeg", "gif" => "image/gif",
				"png" => "image/png", "ico" => "image/x-icon", "pdf" => "application/pdf",
				"tif" => "image/tiff", "tiff" => "image/tiff", "svg" => "image/svg+xml",
				"svgz" => "image/svg+xml", "swf" => "application/x-shockwave-flash", 
				"zip" => "application/zip", "gz" => "application/x-gzip",
				"tar" => "application/x-tar", "bz" => "application/x-bzip",
				"bz2" => "application/x-bzip2",  "rar" => "application/x-rar-compressed",
				"exe" => "application/x-msdownload", "msi" => "application/x-msdownload",
				"cab" => "application/vnd.ms-cab-compressed", "txt" => "text/plain",
				"asc" => "text/plain", "htm" => "text/html", "html" => "text/html",
				"css" => "text/css", "js" => "text/javascript",
				"xml" => "text/xml", "xsl" => "application/xsl+xml",
				"ogg" => "application/ogg", "mp3" => "audio/mpeg", "wav" => "audio/x-wav",
				"avi" => "video/x-msvideo", "mpg" => "video/mpeg", "mpeg" => "video/mpeg",
				"mov" => "video/quicktime", "flv" => "video/x-flv", "php" => "text/x-php"
			);
			// Default the pointer to the name of the file if not provided.
			if (!$pointer) {
				$path_info = BigTree::pathInfo($file);
				$pointer = $path_info["basename"];
			} else {
				$path_info = BigTree::pathInfo($pointer);
			}
			// Get destination mime type
			$content_type = isset($exts[strtolower($path_info["extension"])]) ? $exts[strtolower($path_info["extension"])] : "application/octet-stream";

			// Amazon S3
			if ($this->Service == "amazon") {
				// Get the Amazon code for the access level
				$access_levels = array("private" => "private","read" => "public-read","write" => "public-read-write");
				$acl = $access_levels[$access];
				if (!$acl) {
					return false;
				}

				$response = $this->callAmazonS3("PUT",$container,$pointer,array(),array(
					"Content-Type" => $content_type,
					"Content-Length" => filesize($file)
				),array("x-amz-acl" => $acl),false,$file);
				
				if (!$response) {
					return true;
				}
				$this->_setAmazonError($response);
				return false;
			// Rackspace Cloud Files
			} elseif ($this->Service == "rackspace") {
				
			// Google Cloud Storage
			} elseif ($this->Service == "google") {
				$file_pointer = fopen($file,"r");
				$response = json_decode(BigTree::cURL("https://www.googleapis.com/upload/storage/v1beta2/b/$container/o?name=$pointer&uploadType=media",false,array(CURLOPT_INFILE => $file_pointer,CURLOPT_POST => true, CURLOPT_HTTPHEADER => array("Content-Type: $content_type","Content-Length: ".filesize($file),"Authorization: Bearer ".$this->Settings["token"]))));
				fclose($file_pointer);
				if (isset($response->id)) {
					// Set the access control level if it's publicly accessible
					if ($access == "read") {
						$this->call("b/$container/o/$pointer/acl",json_encode(array("entity" => "allUsers","role" => "READER")),"POST");
					} elseif ($access == "write") {
						$this->call("b/$container/o/$pointer/acl",json_encode(array("entity" => "allUsers","role" => "OWNER")),"POST");
					}
					return true;
				} else {
					foreach ($response->error->errors as $error) {
						$this->Errors[] = $error;
					}
					return false;
				}
			} else {
				return false;
			}
		}

		/*
			Function: callAmazonS3
				Calls the Amazon AWS API.

			Parameters:
				verb - The HTTP verb to use (GET, POST, PUT, DELETE, HEAD)
				bucket - The bucket to affect.
				uri - The resource to affect.
				params - Additional GET parameters.
				request_headers - Additional generic HTTP headers to set.
				amazon_headers - Specific Amazon AWS HTTP headers to set.
				data - POST or PUT data.
				file - File to upload.
		*/
	
		function callAmazonS3($verb = "GET",$bucket = "",$uri = "",$params = array(),$request_headers = array(),$amazon_headers = array(),$data = false,$file = false) {
			$headers = array();
			$resource = "";
			$uri = $uri ? "/".str_replace("%2F","/",rawurlencode($uri)) : "/";
			$host = false;

			if ($bucket) {
				// See if it's a valid domain bucket
				if (strlen($bucket) > 63 ||
					preg_match("/[^a-z0-9\.-]/", $bucket) > 0 ||
					strpos($bucket, '-.') !== false ||
					strpos($bucket, '..') !== false ||
					!preg_match("/^[0-9a-z]/", $bucket) ||
					!preg_match("/[0-9a-z]$/", $bucket)) {
					// Invalid domain
					$host = "s3.amazonaws.com";
					if ($bucket) {
						$uri = "/".$bucket.$uri;
						$bucket = "";
					}
					$resource = $uri;
				} else {
					// Valid domain
					$host = $bucket."."."s3.amazonaws.com";
					$resource = "/".$bucket.$uri;
				}
			} else {
				$host = "s3.amazonaws.com";
				$resource = $uri;
			}
		
			$query = "";
			if (count($params)) {
				$query = (substr($uri,-1) !== "?") ? "?" : "&";
				// Build out the GET vars
				foreach ($params as $key => $val) {
					if (!$val) {
						$query .= "$key&";
					} else {
						$query .= "$key=".rawurlencode($val)."&";
					}
				}
				// Chop off the last &
				$query = substr($query,0,-1);
				$uri .= $query;

				if (array_key_exists("acl",$params) ||
					array_key_exists("location",$params) ||
					array_key_exists("torrent",$params) ||
					array_key_exists("website",$params) ||
					array_key_exists("logging",$params)) {
					$resource .= $query;
				}
			}

			$curl = curl_init();
			curl_setopt($curl,CURLOPT_URL,"http://".($host ? $host : "s3.amazonaws.com").$uri);
			
			// Build out headers
			$date = gmdate("D, d M Y H:i:s T");
			$headers = array("Date: $date");
			if ($host) {
				$headers[] = "Host: $host";
			}
			// Amazon headers
			$amazon_header_signature = array();
			foreach ($amazon_headers as $key => $val) {
				if ($val) {
					$headers[] = "$key: $val";
				}
				// Signature building
				$amazon_header_signature[] = strtolower(trim($key)).":".trim(str_replace(array("\r","\n")," ",$val));
			}
			// Amazon wants this for some reason, I believe
			if (!isset($request_headers["Content-MD5"])) {
				$request_headers["Content-MD5"] = "";
			}
			if (!isset($request_headers["Content-Type"])) {
				$request_headers["Content-Type"] = "";
			}
			// Generic headers
			foreach ($request_headers as $key => $val) {
				if ($val) {
					$headers[] = "$key: $val";
				}
			}

			// Sort Amazon Headers for signature
			if (count($amazon_header_signature)) {
				sort($amazon_header_signature,SORT_STRING);
				$amazon_header_signature = implode("\n",$amazon_header_signature)."\n";
			} else {
				$amazon_header_signature = "";
			}	
			
			$headers[] = "Authorization: AWS ".$this->AmazonKey.":".$this->_hash(
				$this->AmazonSecret,
				$verb."\n".$request_headers["Content-MD5"]."\n".$request_headers["Content-Type"]."\n".$date."\n".$amazon_header_signature.$resource
			);

			curl_setopt($curl,CURLOPT_HTTPHEADER,$headers);
			curl_setopt($curl,CURLOPT_HEADER,false);
			curl_setopt($curl,CURLOPT_RETURNTRANSFER,true);
			curl_setopt($curl,CURLOPT_FOLLOWLOCATION,true);

			// Different methods
			$file_pointer = false;
			if ($verb == "PUT") {
				if ($file) {
					curl_setopt($curl,CURLOPT_PUT,true);
					curl_setopt($curl,CURLOPT_INFILESIZE,filesize($file));
					$file_pointer = fopen($file,"r");
					curl_setopt($curl,CURLOPT_INFILE,$file_pointer);
				} elseif ($data) {
					curl_setopt($curl,CURLOPT_CUSTOMREQUEST,"PUT");
					curl_setopt($curl,CURLOPT_POSTFIELDS,$data);
				} else {
					curl_setopt($curl,CURLOPT_CUSTOMREQUEST,"PUT");
				}
			} elseif ($verb == "POST") {
				curl_setopt($curl,CURLOPT_POST,true);
				curl_setopt($curl,CURLOPT_POSTFIELDS,$data);
			} elseif ($verb == "HEAD") {
				curl_setopt($curl,CURLOPT_CUSTOMREQUEST,"HEAD");
				curl_setopt($curl,CURLOPT_NOBODY,true);
			} elseif ($verb == "DELETE") {
				curl_setopt($curl,CURLOPT_CUSTOMREQUEST,"DELETE");
			}

			$response = curl_exec($curl);
			if ($file_pointer) {
				fclose($file_pointer);
			}
			$this->HTTPResponseCode = curl_getinfo($curl,CURLINFO_HTTP_CODE);
			return $response;
		}

		/*
			Function: callRackspace
				cURL wrapper for Rackspace.

			Parameters:
				endpoint - Endpoint to hit.
				data - Request body data.
				curl_options - Additional cURL options.
		*/

		function callRackspace($endpoint = "",$data = false,$curl_options = array()) {
			$curl_options = $curl_options + array(CURLOPT_HTTPHEADER => array("Accept: application/json","X-Auth-Token: ".$this->Settings["rackspace"]["token"]));
			return json_decode(BigTree::cURL($this->RackspaceAPIEndpoint.($endpoint ? "/$endpoint" : ""),$data,$curl_options));
		}
	}
?>