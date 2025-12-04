<?php
// Fallback routing for Order page
if (strpos($_SERVER['REQUEST_URI'], '/order') !== false) {
    include(locate_template('page-order.php'));
    exit;
}

get_header();
?>

<div class="container" style="padding: 60px 0;">
    <h1>Welcome to Helping Assignments</h1>
    <p>This is the default index template. If you are seeing this on the Order page, the template is not loading correctly.</p>
</div>

<?php get_footer(); ?>