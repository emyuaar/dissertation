<?php
get_header();

// 1. DYNAMIC CATEGORY QUERY FIX:
// Ensure posts load correctly for archives
$paged = (get_query_var('paged')) ? get_query_var('paged') : (get_query_var('page') ? get_query_var('page') : 1);
$current_cat_id = is_category() ? get_queried_object_id() : 0;
?>

<div class="blog-listing-header">
    <div class="container hero-container">
        <h1><?php echo get_the_archive_title(); ?></h1>
        <p><?php echo get_the_archive_description(); ?></p>
    </div>
</div>

<div class="blog-grid-wrapper">
    <div class="container archive-layout">

        <!-- MAIN CONTENT AREA -->
        <main class="archive-main">
            
            <!-- Category Filter Row -->
            <div class="blog-categories-filter">
                <a href="<?php echo home_url('/blog'); ?>" class="cat-filter-btn">All Topics</a>
                <?php
                $categories = get_categories(array('orderby' => 'name', 'order' => 'ASC', 'hide_empty' => 1));
                foreach ( $categories as $cat ) {
                    if($cat->name == 'Uncategorized') continue;
                    $active_class = ($cat->term_id === $current_cat_id) ? 'active' : '';
                    echo '<a href="' . get_category_link($cat->term_id) . '" class="cat-filter-btn ' . $active_class . '">' . esc_html($cat->name) . '</a>';
                }
                ?>
            </div>

            <div class="blog-grid">
                <?php
                if ( have_posts() ) :
                    while ( have_posts() ) : the_post();
                        $post_categories = get_the_category();
                        $cat_name = !empty($post_categories) ? esc_html($post_categories[0]->name) : 'Article';
                ?>
                    <div class="blog-card">
                        <div class="blog-card-header">
                            <h2 class="blog-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                            <div class="blog-card-badges">
                                <span class="badge-info"><?php echo $cat_name; ?></span>
                                <?php if (has_tag('featured') || get_the_time('U') > (time() - 604800)): ?>
                                    <span class="badge-update">Latest Update</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="blog-card-body">
                            <div class="blog-card-img-box">
                                <a href="<?php the_permalink(); ?>">
                                    <?php if (has_post_thumbnail()) : ?>
                                        <?php the_post_thumbnail('medium_large', ['class' => 'blog-card-img', 'alt' => get_the_title()]); ?>
                                    <?php else: ?>
                                        <div class="blog-card-img placeholder">
                                            <span>No Image Found</span>
                                        </div>
                                    <?php endif; ?>
                                </a>
                            </div>
                            
                            <div class="blog-card-right">
                                <div class="blog-card-excerpt">
                                    <?php echo wp_trim_words( get_the_excerpt(), 22, '...' ); ?>
                                </div>
                                <div class="blog-card-footer">
                                    <a href="<?php the_permalink(); ?>" class="read-more-btn">
                                        Read Full Article &rarr;
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php
                    endwhile;
                ?>
            </div>
            
            <!-- Pagination -->
            <div class="pagination-wrapper">
                <?php
                the_posts_pagination( array(
                    'mid_size'  => 2,
                    'prev_text' => __( '&laquo; Prev' ),
                    'next_text' => __( 'Next &raquo;' ),
                    'class'     => 'pagination'
                ) );
                ?>
            </div>

            <?php
            else :
                echo '<div class="no-posts-box"><h3>No Articles In This Category</h3><p>We are currently uploading new guides for this specific topic. Please check back shortly.</p></div>';
            endif;
            ?>
        </main>

        <!-- SIDEBAR AREA -->
        <aside class="archive-sidebar">
            <div class="sidebar-sticky">

                <div class="widget widget-help">
                    <div class="widget-help-icon">
                        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <h4>Support On Any Budget</h4>
                    <p>Find the best academic solutions tailored for students of all levels.</p>
                    <a href="<?php echo home_url('/order'); ?>" class="btn btn-whatsapp" style="background:var(--primary-color);">Get Started Now</a>
                </div>

                <div class="widget widget-recent">
                    <h4 class="widget-title">Recent Topics</h4>
                    <ul class="recent-posts-list">
                        <?php 
                        $recent_args = array('posts_per_page' => 4);
                        $recent_posts = new WP_Query($recent_args);
                        if($recent_posts->have_posts()) :
                            while($recent_posts->have_posts()): $recent_posts->the_post();
                            ?>
                            <li>
                                <a href="<?php the_permalink(); ?>">
                                    <span class="recent-title"><?php echo wp_trim_words(get_the_title(), 7, '...'); ?></span>
                                </a>
                            </li>
                            <?php
                            endwhile;
                            wp_reset_postdata();
                        endif;
                        ?>
                    </ul>
                </div>

                <div class="widget widget-service-cta" style="background:#22c55e; padding: 25px; border-radius: 16px;">
                    <h4 style="color:#fff; margin-bottom: 5px;">Plagiarism Report Free</h4>
                    <p style="color:#fff; font-size:0.85rem; margin-bottom: 12px; opacity: 0.9;">We provide valid Turnitin reports with every dissertation.</p>
                    <a href="<?php echo home_url('/full-dissertation-writing'); ?>" style="color:#fff; font-weight:800; text-decoration:none; font-size:0.8rem; border-bottom: 2px solid rgba(255,255,255,0.3);">View Details</a>
                </div>

            </div>
        </aside>

    </div>
</div>

<?php get_footer(); ?>
