<div class="form_container">
	<section>
		<p><img src="<?=ADMIN_ROOT?>images/spinner.gif" alt="" /> &nbsp; Please wait while we retrieve your Google Analytics information.</p>
	</section>
</div>
<script type="text/javascript">
	$.ajax("<?=ADMIN_ROOT?>ajax/dashboard/analytics/cache/", { success: function(response) {
		if (response) {
			document.location.href = "<?=$mroot?>";
		} else {
			BigTree.growl("Analytics","Caching Failed",5000,"error");
			$(".form_container section p").html('Caching failed. Please return to the configuration screen by <a href="../configure/">clicking here</a>.');
		}
	}});
</script>