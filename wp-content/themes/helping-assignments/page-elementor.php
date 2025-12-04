<?php
/* Template Name: Elementor Full Width */

get_header();
?>

<div class="ha-elementor-container">
    <?php
    // Elementor Content
    while ( have_posts() ) :
        the_post();
        the_content();
    endwhile;
    ?>
</div>

<?php get_footer(); ?>
