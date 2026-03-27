<?php
get_header();

// 1. DYNAMIC QUERY FIX:
// Ensure posts load even if this template is included manually from index.php router fallback
$paged = (get_query_var('paged')) ? get_query_var('paged') : (get_query_var('page') ? get_query_var('page') : 1);
$args = array(
    'post_type' => 'post',
    'post_status' => 'publish',
    'paged' => $paged,
    'posts_per_page' => 10
);
$blog_query = new WP_Query($args);
?>

<div class="blog-listing-header">
    <div class="container hero-container">
        <h1>Our Latest Academic Blogs</h1>
        <p>Expert dissertation guidance, professional writing tips, and student success strategies from our PhD experts.</p>
    </div>
</div>

<div class="blog-grid-wrapper">
    <div class="container archive-layout">
        
        <!-- MAIN CONTENT AREA -->
        <main class="archive-main">
            
            <!-- Category Filter Row -->
            <nav class="blog-categories-filter">
                <a href="<?php echo home_url('/blog'); ?>" class="cat-filter-btn active">All Guides</a>
                <?php
$categories = get_categories(array('orderby' => 'name', 'order' => 'ASC', 'hide_empty' => 1));
foreach ($categories as $cat) {
    if ($cat->name == 'Uncategorized')
        continue;
    echo '<a href="' . get_category_link($cat->term_id) . '" class="cat-filter-btn">' . esc_html($cat->name) . '</a>';
}
?>
            </nav>

            <?php if ($blog_query->have_posts()): ?>
                
                <!-- FEATURED POST (Only on page 1) -->
                <?php if ($paged == 1):
        $blog_query->the_post(); ?>
                    <div class="featured-post-card">
                        <div class="featured-tag">Latest Update</div>
                        <div class="featured-flex">
                            <div class="featured-img-box">
                                <?php if (has_post_thumbnail()):
            the_post_thumbnail('large');
        else: ?>
                                    <div class="placeholder-img">📚</div>
                                <?php
        endif; ?>
                            </div>
                            <div class="featured-content">
                                <span class="category-badge"><?php $c = get_the_category();
        echo $c[0]->name; ?></span>
                                <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                                <p><?php echo wp_trim_words(get_the_excerpt(), 30); ?></p>
                                <a href="<?php the_permalink(); ?>" class="read-more-btn">Read Full Article &rarr;</a>
                            </div>
                        </div>
                    </div>
                <?php
    endif; ?>

                <!-- Blog Grid -->
                <div class="blog-grid">
                    <?php
    while ($blog_query->have_posts()):
        $blog_query->the_post();
        $post_categories = get_the_category();
        $cat_name = !empty($post_categories) ? esc_html($post_categories[0]->name) : 'Article';
?>
                        <div class="blog-card">
                            <a href="<?php the_permalink(); ?>" class="blog-card-img-link">
                                <?php if (has_post_thumbnail()): ?>
                                    <?php the_post_thumbnail('medium_large', ['class' => 'blog-card-img']); ?>
                                <?php
        else: ?>
                                    <div class="blog-card-img placeholder"><span>No Image</span></div>
                                <?php
        endif; ?>
                            </a>
                            
                            <div class="blog-card-content">
                                <span class="category"><?php echo $cat_name; ?></span>
                                <h3 class="blog-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                                <div class="blog-card-excerpt"><?php echo wp_trim_words(get_the_excerpt(), 18); ?></div>
                                <div class="blog-card-footer">
                                    <a href="<?php the_permalink(); ?>" class="read-more-btn">Read More &rarr;</a>
                                </div>
                            </div>
                        </div>
                    <?php
    endwhile; ?>
                </div>
                
                <!-- Dynamic Pagination -->
                <div class="pagination-wrapper">
                    <?php
    echo paginate_links(array(
        'base' => str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999))),
        'format' => '?paged=%#%',
        'current' => max(1, $paged),
        'total' => $blog_query->max_num_pages,
        'prev_text' => __('&laquo; Prev'),
        'next_text' => __('Next &raquo;'),
        'type' => 'list'
    ));
?>
                </div>

            <?php
else: ?>
                <div class="no-posts-box">
                    <div class="no-posts-icon">📋</div>
                    <h3>No Articles Found</h3>
                    <p>We are currently updating our archive with fresh academic insights. Please check back shortly for expert guides and dissertation tips.</p>
                    <a href="<?php echo home_url(); ?>" class="btn-primary" style="display:inline-block; padding:10px 25px; border-radius:8px; margin-top:15px; text-decoration:none;">Go to Homepage</a>
                </div>
            <?php
endif;
wp_reset_postdata(); ?>

        </main>

        <!-- SIDEBAR AREA -->
        <aside class="archive-sidebar">
            <div class="sidebar-sticky">

                <!-- Support Widget -->
                <div class="widget widget-help-archive">
                    <h4>Need Urgent Help?</h4>
                    <p>Chat with our academic advisors on WhatsApp for an instant quote.</p>
                    <a href="https://wa.me/447782200976" target="_blank" class="btn-whatsapp-dynamic">Message on WhatsApp</a>
                </div>

                <!-- Expertise Widget -->
                <div class="widget widget-expertise">
                    <h4 class="widget-title">The ODA Guarantee</h4>
                    <ul class="check-list">
                        <li>PhD Qualified British Experts</li>
                        <li>100% Plagiarism Free Guarantee</li>
                        <li>On-Time Delivery Or Refund</li>
                        <li>24/7 Unlimited Support</li>
                    </ul>
                </div>

                <!-- Recent Topics -->
                <div class="widget widget-recent-simplified">
                    <h4 class="widget-title">Popular Topics</h4>
                    <div class="tag-cloud">
                        <?php
$tags = get_tags(array('hide_empty' => false, 'number' => 8));
if ($tags) {
    foreach ($tags as $tag) {
        echo '<a href="' . get_tag_link($tag->term_id) . '" class="tag-link">#' . $tag->name . '</a>';
    }
}
else {
    echo '<span style="color:#94a3b8; font-size:0.9rem;">No topics listed yet.</span>';
}
?>
                    </div>
                </div>

                <!-- Trusted Badge -->
                <div class="widget widget-trust">
                    <img src="https://onlinedissertationadvisors.co.uk/wp-content/uploads/2026/03/dmca.png" alt="DMCA">
                    <p>Your academic data is 100% secured and encrypted.</p>
                </div>

            </div>
        </aside>

    </div> 
</div>

<?php get_footer(); ?>
