<?php
// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
   exit;
}

if (!function_exists('brtpmj_config_callback')) {
	function brtpmj_config_callback(){
		
		if (!current_user_can('manage_options')){
			wp_die( esc_html( __('You do not have sufficient permissions to access this page.', 'better-business-reviews') ) );
		}
		
		global $brtpmj_plugin_url;
		
	?>
		<!-- Pages HTMl -->
		<div class="wrap">
			<div id="brtpmj_settings">
				<form method='POST' action="" id="brtpmj_admin_setting_frm_free">
					<?php 
					wp_nonce_field('brtpmj_settings_option');
					?>
					<div class="brtpmj_setting-container">
						<h2 class="brtpmj_admin_heading">General Settings <a href="https://trustpilotplugin.com/" class="brtpmj_get_pro" target="_blank">Display More Reviews with PRO Plugin</a></h2>
						<div class="brtpmj_inner-container">
							<div class="brtpmj_col-1">
								<label for="brtpmj_url">Business Profile URL</label>
							</div>
							<div class="brtpmj_col-2">
								<?php $brtpmj_url = get_option('brtpmj_url'); ?>
								<input type="text" name="brtpmj_url" id="brtpmj_url" class="brtpmj_input" value="<?php echo esc_url( $brtpmj_url ); ?>">
								<input type="submit" name="brtpmj_settings_submit" class="brtpmj_adm_btn" value="Fetch Your Reviews"/>
								<div class="brtpmj_info">
									<span id="brtpmj_admin_sync_progress"><img src="<?php echo esc_url( $brtpmj_plugin_url ); ?>/assets/bbr-loading-icon.gif"> Fetching Your Reviews...</span>
									<span id="brtpmj_admin_sync_status"></span>
								</div>
							</div>								
						</div>
					</div>
				</form>
			</div>
			<div class="brtpmj_setting-container">
			
				<h2 class="brtpmj_admin_heading">Shortcodes</h2>
				<div class="brtpmj_inner-container">
					<div class="brtpmj_col-1">
						<label>List Layout (Default)</label>	
					</div>
					<div class="brtpmj_col-2">
					<span class="brtpmj_shortcode">[brtpmj_reviews]</span>
					</div>								
				</div>
				<div class="brtpmj_inner-container">
					<div class="brtpmj_col-1">
						<label>Grid Layout</label>	
					</div>
					<div class="brtpmj_col-2">
					<span class="brtpmj_shortcode">[brtpmj_reviews layout="grid"]</span>
					</div>								
				</div>
				<div class="brtpmj_inner-container">
					<div class="brtpmj_col-1">
						<label>Carousel Layout</label>	
					</div>
					<div class="brtpmj_col-2">
					<span class="brtpmj_shortcode">[brtpmj_reviews layout="carousel"]</span>
					</div>								
				</div>
				<div class="brtpmj_inner-container">
					<div class="brtpmj_col-1">
						<label>Post Limit (PRO)</label>	
					</div>
					<div class="brtpmj_col-2">
						<span class="brtpmj_shortcode">[brtpmj_reviews limit="8"]</span>
					</div>								
				</div>
				<div class="brtpmj_inner-container">
					<div class="brtpmj_col-1">
						<label>Minimum Stars (PRO)</label>	
					</div>
					<div class="brtpmj_col-2">
						<span class="brtpmj_shortcode">[brtpmj_reviews min_stars="4"]</span>
					</div>								
				</div>
				<div class="brtpmj_inner-container">
					<div class="brtpmj_col-1">
						<label>Compact Widget (PRO)</label>	
					</div>
					<div class="brtpmj_col-2">
						<span class="brtpmj_shortcode">[brtpmj_compact_widget]</span>
					</div>								
				</div>
			</div>

			<div class="brtpmj_setting-container">
				<h2 class="brtpmj_admin_heading">Customizer (Available in PRO version)</h2>

				<form>

					<div class="brtpmj_inner-container">
						<div class="brtpmj_col-1">
							<label>Enable Floating Widget</label>
						</div>

						<div class="brtpmj_col-2">
							<input type="checkbox">	
							<select>
								<option value="">Left</option>
								<option value="">Right</option>
							</select>
						</div>
					</div>
					
					<div class="brtpmj_inner-container">
						<div class="brtpmj_col-1">
							<label>Hide Logo</label>
						</div>

						<div class="brtpmj_col-2">
							<input type="checkbox">	
						</div>
					</div>

					<div class="brtpmj_inner-container">
						<div class="brtpmj_col-1">
							<label>Hide Name</label>
						</div>

						<div class="brtpmj_col-2">
							<input type="checkbox">	
						</div>
					</div>

					<div class="brtpmj_inner-container">
						<div class="brtpmj_col-1">
							<label>Hide Review Count</label>
						</div>

						<div class="brtpmj_col-2">
							<input type="checkbox">	
						</div>
					</div>
					
					<div class="brtpmj_inner-container">
						<div class="brtpmj_col-1">
							<label>Hide Review Stars</label>
						</div>

						<div class="brtpmj_col-2">
							<input type="checkbox">	
						</div>
					</div>

					<div class="brtpmj_inner-container">
						<div class="brtpmj_col-1">
							<label>Hide Add Review</label>
						</div>

						<div class="brtpmj_col-2">
							<input type="checkbox">	
						</div>
					</div>

					<div class="brtpmj_inner-container">
						<div class="brtpmj_col-1">
							<label>Post limit</label>
						</div>

						<div class="brtpmj_col-2">
							<input type="number">	
						</div>
					</div>

					<div class="brtpmj_inner-container">
						<div class="brtpmj_col-1">
							<?php  
								$filters = array(
									'list' => 'List',
									'grid' => 'Grid',
									'carousel' => 'Carousel',
								);
								$options = '';
								foreach($filters as $key => $val){
									$options .= '<option value="'.$key.'">'.$val.'</option>';
								}
							
							?>
							<label>Layout</label>
						</div>

						<div class="brtpmj_col-2">
							<select><?php echo $options; ?></select>
						</div>
					</div>

					<div class="brtpmj_inner-container">
						<div class="brtpmj_col-1">
							<?php 
								$filters = array(

									'all' => 'All stars',
									'5' => 'Five stars',
									'4' => 'Four stars and above',
									'3' => 'Three stars and above',
									'2' => 'Two stars and above',
									'1' => 'One star and above',

								);
								$options = '';
								foreach($filters as $key => $val){
								
									$options .= '<option value="'.$key.'">'.$val.'</option>';
								}
							
							?>
							<label>Filter by rating</label>
						</div>

						<div class="brtpmj_col-2">
							<select><?php echo $options; ?></select>
						</div>
					</div>

					<div class="brtpmj_inner-container">
						<div class="brtpmj_col-1">
							<label>Description Words Limit</label>
						</div>

						<div class="brtpmj_col-2">
							<input type="number">	
						</div>
					</div>
				</form>
			</div>
		</div>
	
	<?php
	}
}
