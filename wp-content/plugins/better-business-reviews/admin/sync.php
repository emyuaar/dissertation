<?php
/*
* Handle ajax calls to sync the reviews
*/

// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
   exit;
}

// Fetch Reviews Data
add_action( 'wp_ajax_brtpmj_fetch_reviews', 'brtpmj_fetch_reviews' );
if (!function_exists('brtpmj_fetch_reviews')) {
	function brtpmj_fetch_reviews(){
		$brtpmj_res_array = array();
		if ( !current_user_can( 'manage_options' ) || !isset( $_POST['brtpmj_admin_nonce'] ) || !wp_verify_nonce( sanitize_text_field( wp_unslash ( $_POST['brtpmj_admin_nonce'] ) ), 'brtpmj_ajax_nonce' ) ) {
			header( 'HTTP/1.1 400 Empty POST Values' );
			$brtpmj_res_array['error'] = __('Error - Could not verify POST values', 'better-business-reviews');
			echo wp_json_encode($brtpmj_res_array);
			exit;
		}
		
		if( isset($_POST['reviews_profile_url']) && $_POST['reviews_profile_url'] != '' && $_POST['reviews_profile_url'] != 'undefined' ){
			$brtpmj_url = sanitize_url($_POST['reviews_profile_url']);
			if (filter_var($brtpmj_url, FILTER_VALIDATE_URL) === FALSE){
				// not a valid URL
				$brtpmj_res_array['error'] = __('Error - Could not verify POST values', 'better-business-reviews');
			}
			else
			{
				update_option('brtpmj_url', $brtpmj_url);
				
				// commented below because we need to send full url as it may be from other language. e.g. https://br.trustpilot.com/review/etc.com and we would need to fetch from that.
				// $brtpmj_url = basename($brtpmj_url);
				
				// $response = wp_remote_get( $brtpmj_url );
				
				$args = [
					'timeout'     => 20,
					'redirection' => 5,
					'httpversion' => '1.1',
					'blocking'    => true,
					'headers' => [
						'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
						'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
						'Accept-Language' => 'en-US,en;q=0.9',
						'Accept-Encoding' => 'gzip, deflate, br',
						'Connection'      => 'keep-alive',
						'Upgrade-Insecure-Requests' => '1',
						'Cache-Control'   => 'no-cache',
						'Pragma'          => 'no-cache',
						'Referer'         => 'https://www.google.com/',
					]
				];

				// $response = wp_remote_get($brtpmj_url, $args);
				
				$api_url = add_query_arg([
					'target_url'  => $brtpmj_url,
				], 'https://api.trustpilotplugin.com/');

				$response = wp_remote_get($api_url, $args);

				if(!is_wp_error($response)){

					$tp_html = wp_remote_retrieve_body($response);

					if(!empty($tp_html)){

						$tp_html = mb_convert_encoding($tp_html, 'HTML-ENTITIES', 'UTF-8');
						libxml_use_internal_errors(true);
						$doc = new DOMDocument();
						@$doc->loadHTML($tp_html);
						$xpath = new DOMXPath($doc);

						$script_data_obj = $xpath->query("//script[@id='__NEXT_DATA__']");
						$emptyObject = new stdClass();
						$script_data = $emptyObject;
						if($script_data_obj->length){
							$script_data_str = $script_data_obj->item(0)->nodeValue;
							$script_data = json_decode($script_data_str);
						}

						if(isset($script_data->props->pageProps)){

							$pageProps = $script_data->props->pageProps;
							
							// busines unit
							$unit				= $pageProps->businessUnit;
							$displayName		= sanitize_text_field( $unit->displayName );
							$identifyingName	= sanitize_text_field( $unit->identifyingName );
							$numberOfReviews	= sanitize_text_field( $unit->numberOfReviews );
							$score				= sanitize_text_field( $unit->trustScore );
							$profileImageUrl	= sanitize_text_field( $unit->profileImageUrl );
							$stars				= sanitize_text_field( $unit->stars );
							
							// Add to options
							update_option('brtpmj_bu_displayname', $displayName);
							update_option('brtpmj_bu_identifyingname', $identifyingName);
							update_option('brtpmj_bu_numberofreviews', $numberOfReviews);
							update_option('brtpmj_bu_score', $score);
							update_option('brtpmj_bu_profileimageurl', $profileImageUrl);
							update_option('brtpmj_bu_stars', $stars);
							
							// Reviews - insert custom posts
							$reviews = $pageProps->reviews;

							if(is_array($reviews)){
								$reviews = array_slice($reviews, 0, 3);
								
								$reversed_array_reviews = array_reverse($reviews);
								
								// let's remove past entries first
								$current_reviews = get_posts(array(
									'numberposts'	=>	-1,
									'status'		=> 'any',
									'post_type'		=>	'brtpmj_review'
								));
								foreach($current_reviews as $cr){
									wp_delete_post($cr->ID, true);
								}
								
								// insert new reviews
								$inserted = 0;
								
								foreach($reversed_array_reviews as $review){
									$title		= sanitize_text_field( $review->title );
									$text		= sanitize_text_field( $review->text );
									$rating		= sanitize_text_field( $review->rating );
									
									$reviewDate	= date('Y-m-d', strtotime( sanitize_text_field( $review->dates->publishedDate ) ));
									
									$consumer	= $review->consumer;
									$cName		= sanitize_text_field( $consumer->displayName );
									$cimage		= sanitize_text_field( $consumer->imageUrl );
									
									$initials_img = '';
									if(!$cimage || $cimage == ''){
										preg_match_all('/\b\w/', $cName, $matches);
										$initials_img = '<span>' . implode( $matches[0] ) . '</span>';
									}
									else{
										$initials_img = '<img src="' . $cimage . '" alt="' . esc_attr($cName) . '">';
									}
									
									$post_args = array(
										'post_title'	=>	$title, 
										'post_type'		=>	'brtpmj_review', 
										'post_status'	=>	'publish',
										'post_content'	=>	$text
									);
									
									$id = wp_insert_post($post_args);
									
									if(!is_wp_error($id)){
										
										update_post_meta($id, 'rating', $rating);
										update_post_meta($id, 'reviewDate', $reviewDate);
										update_post_meta($id, 'cName', $cName);
										update_post_meta($id, 'cimage', $initials_img);
										
										$inserted++;
										
									}
									
								}
								
								$brtpmj_res_array['success'] = 1;
								$brtpmj_res_array['inserted_count'] = $inserted;
								
							}
							
						}
						else{
							$brtpmj_res_array['error'] = __("Error getting reviews. Please make sure to enter correct profile URL.", "better-business-reviews");
						}
						
					}
					else{
						$brtpmj_res_array['error'] = __("Error getting reviews. Empty Body! Please try again.", "better-business-reviews");
					}
					
				}
				else {
					$brtpmj_res_array['error'] = __("Error getting reviews. No response! Please try again.", "better-business-reviews");
				}
				
				
				
			}
		}
		
		echo wp_json_encode($brtpmj_res_array);
		exit;
	}
}