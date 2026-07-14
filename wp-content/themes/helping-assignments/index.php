<?php
/**
 * The main template file.
 * This is the most generic template file in a WordPress theme and one of the two 
 * required files for a theme (the other being style.css).
 * It is used to display a page when nothing more specific matches a query. this is test
 */

get_header();

if ( have_posts() ) :
    while ( have_posts() ) : the_post();
        ?>
        <div class="container" style="padding: 60px 0;">
            <h1><?php the_title(); ?></h1>
            <div class="entry-content">
                <?php the_content(); ?>
            </div>
        </div>
        <?php
    endwhile;
else :
    ?>
    <div class="container" style="padding: 100px 0; text-align: center;">
        <h1>No Content Found</h1>
        <p>Sorry, the content you are looking for does not exist on our servers.</p>
        <a href="<?php echo home_url('/'); ?>" class="ha-cta-clean">Back Home</a>
    </div>
    <?php
endif;

get_footer();