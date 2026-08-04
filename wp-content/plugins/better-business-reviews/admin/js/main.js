jQuery(document).ready(function ($) {
	
	function fetch_reviews(){
		var fd = new FormData();
		var reviews_profile_url = $('#brtpmj_url').val();
		fd.append('reviews_profile_url', reviews_profile_url);
		fd.append('brtpmj_admin_nonce', ajax_brtpmj_admin_obj.nonce);
		fd.append('action', 'brtpmj_fetch_reviews');
		
		jQuery.ajax({
			url: ajax_brtpmj_admin_obj.ajaxurl,
			type: 'POST',
			data: fd,
			contentType: false,
			processData: false,
			dataType: "JSON",
			beforeSend: function() {
				$('#brtpmj_admin_sync_status').html('');
				$('#brtpmj_admin_sync_progress').show();
			},
		})
		.done(function (results) {
			if (results.error) {
				console.log(results.error);
			}
			else {
				if( results.success == 1 ){
					$('#brtpmj_admin_sync_progress').hide();
					$('#brtpmj_admin_sync_status').html( results.inserted_count + ' Reviews Added.');
				}
			}
		})
		.fail(function (data) {
			console.log(data.responseText);
			console.log('Request Failed. Status - ' + data.statusText);
		});
	}
	
	$('#brtpmj_settings form#brtpmj_admin_setting_frm_free').on('submit', function(e){
		e.stopPropagation();
		e.preventDefault();
		fetch_reviews();
	});
	
	
});