<?php
/*
* Shortcode to display new post form
*/

// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
   exit;
}

// Fetch all threads
add_shortcode('brtpmj_reviews', 'brtpmj_reviews_callback');
if (!function_exists('brtpmj_reviews_callback')) {
	function brtpmj_reviews_callback($atts){
		if( !is_admin() ){ // only run when in front-end
			
			// parameters
			$layout = 'list';
			$container_class = '';

			if(isset($atts['layout'])) {
				$layout = sanitize_text_field($atts['layout']);
			}
			
			wp_enqueue_style('brtpmj-style');
			wp_enqueue_script('brtpmj-script');

			if($layout == 'grid') {
				$container_class = ' brtpmj_grid_container';
				wp_enqueue_style('brtpmj-grid-style');
			}
			if($layout == 'carousel') {
				$container_class = ' carousel';
				wp_enqueue_style('brtpmj-carousel-style');
				wp_enqueue_script('brtpmj-carousel-script');
			}
			
			global $brtpmj_plugin_url;
			
			// get business info
			$brtpmj_bu_displayname		= get_option('brtpmj_bu_displayname');
			$brtpmj_bu_identifyingname	= get_option('brtpmj_bu_identifyingname');
			$brtpmj_bu_numberofreviews	= get_option('brtpmj_bu_numberofreviews');
			$brtpmj_bu_score			= get_option('brtpmj_bu_score');
			$brtpmj_bu_profileimageurl	= get_option('brtpmj_bu_profileimageurl');
			$brtpmj_bu_stars			= get_option('brtpmj_bu_stars');
			
			// get all reviews
			$bbr_all_reviews = get_posts(array(
				'numberposts'	=>	-1,
				'status'		=> 'publish',
				'post_type'		=>	'brtpmj_review',
				'order' 		=>	'DESC',
				'orderby'		=>	'ID',
			));
			
			$html = '<div class="brtpmj_reviews_container">';
			
			// Prepare html for Reviews
			$rvw_html = '';
			if(is_array($bbr_all_reviews)){
				foreach($bbr_all_reviews as $review){
					
					$title		= get_the_title($review->ID);
					$text		= get_the_content(null, false, $review->ID);
					$rating		= get_post_meta($review->ID, 'rating', true);
					$reviewDate	= get_post_meta($review->ID, 'reviewDate', true);
					
					$cName			= get_post_meta($review->ID, 'cName', true);
					$initials_img	= get_post_meta($review->ID, 'cimage', true);
					
					$rvw_html .= '<div class="brtpmj_single_rvw' . ($layout == 'carousel' ? ' carousel-item' : '') . '">
									
						<div class="brtpmj_sr_author_overview">
							
							<div class="brtpmj_sr_ab_img">' . $initials_img . '</div>
							<div class="brtpmj_sr_ab_name_date">
								<span class="brtpmj_sr_ab_name">' . $cName . '</span>
								<span class="brtpmj_sr_ab_date">' . $reviewDate . '</span>
							</div>
							
						</div>
						
						<div class="brtpmj_sr_rating">
							<span class="brtpmj_br_score_img"><img src="' . $brtpmj_plugin_url . 'assets/stars-' . $rating . '.svg" alt="' . esc_attr($rating) . ' stars"></span>
						</div>
						
						<div class="brtpmj_sr_title">
							<h4>' . $title . '</h4>
						</div>
						
						<div class="brtpmj_sr_title">
							<p>' . $text . '</p>
						</div>
						
					</div>';
				}
			}
			
			// Append to html
			$html .= '<div class="brtpmj_businessheader">
						<img src="'.$brtpmj_bu_profileimageurl.'" alt="' . esc_attr($brtpmj_bu_displayname) . '">
						<h2 class="brtpmj_business_name">' . $brtpmj_bu_displayname . '</h2>
					</div>';
			
			$html .= '<p class="brtpmj_business_ratings">
						<span class="brtpmj_br_score">' . $brtpmj_bu_score . '</span>
						<span class="brtpmj_br_score_img"><img src="' . $brtpmj_plugin_url . 'assets/stars-' . $brtpmj_bu_stars . '.svg" alt="' . esc_attr($brtpmj_bu_stars) . ' stars"></span>
						<span class="brtpmj_br_count">' . $brtpmj_bu_numberofreviews . ' Reviews</span>
					</p>';
					
			$html .= '<div class="' . ($layout == 'carousel' ? 'carousel-container': '') . '">';
			$html .= '<div class="brtpmj_all_reviews' . $container_class .'">' . $rvw_html . '</div>';

			if($layout == 'carousel') {
				$html .= '
					<div class="carousel-nav" data-index="0">
						<button class="slider-btn slider-prev">&#10094;</button>
						<button class="slider-btn slider-next">&#10095;</button>
					</div>
				';
			}
			$html .= '</div>';
			
			$html .= '</div>';
			
			return $html;
			
		}
	}
}
