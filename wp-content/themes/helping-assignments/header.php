<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/style.css?v=<?php echo time(); ?>">
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>

<!-- TOP INFO BAR -->
<div class="ha-topbar">
    <div class="container ha-topbar-inner"><!-- NOTE: container, not ha-container -->
        <div class="ha-topbar-left">
            🎓 Trusted UK Dissertation & Assignment Writers — <span>24/7 Support</span>
        </div>

        <div class="ha-topbar-right">
            <a href="tel:+447782200976" class="ha-top-link">📞 +44 7782 200976</a>
            <span class="ha-top-sep">•</span>
            <a href="mailto:info@onlinedissertationadvisors.co.uk" class="ha-top-link">✉ info@onlinedissertationadvisors.co.uk</a>
            <a href="https://wa.me/447782200976" class="ha-top-whatsapp" target="_blank">
                WhatsApp Chat
            </a>
        </div>
    </div>
</div>

<!-- MAIN HEADER -->
<header class="ha-header">
    <div class="container ha-header-main"><!-- NOTE: container here as well -->

        <!-- LOGO -->
        <div class="ha-logo">
            <a href="<?php echo home_url(); ?>">
                <img src="<?php echo get_template_directory_uri(); ?>/images/logo (2).png?v=<?php echo time(); ?>" alt="Helping Assignments">
            </a>
        </div>

        <!-- NAVIGATION -->
        <nav class="ha-nav">
            <ul class="ha-nav-list">
                <li><a href="<?php echo home_url(); ?>">Home</a></li>

                <!-- Dissertation Writing -->
                <li class="ha-has-dropdown">
                    <a href="<?php echo home_url('/dissertation-writing'); ?>">
                        Dissertation Writing <span class="ha-caret"></span>
                    </a>
                    <ul class="ha-dropdown-menu">
                        <li><a href="<?php echo home_url('/dissertation-proposal-help'); ?>">Dissertation Proposal</a></li>
                        <li><a href="<?php echo home_url('/full-dissertation-writing'); ?>">Full Dissertation</a></li>
                        <li><a href="<?php echo home_url('/dissertation-chapters'); ?>">Individual Chapters</a></li>
                    </ul>
                </li>

                <!-- Editing -->
                <li class="ha-has-dropdown">
                    <a href="<?php echo home_url('/editing-proofreading'); ?>">
                        Editing <span class="ha-caret"></span>
                    </a>
                    <ul class="ha-dropdown-menu">
                        <li><a href="<?php echo home_url('/assignment-editing'); ?>">Assignment Editing</a></li>
                        <li><a href="<?php echo home_url('/dissertation-editing'); ?>">Dissertation Editing</a></li>
                        <li><a href="<?php echo home_url('/proofreading'); ?>">Proofreading</a></li>
                    </ul>
                </li>

                <!-- Other Services -->
                <li class="ha-has-dropdown">
                    <a href="<?php echo home_url('/other-services'); ?>">
                        Other Services <span class="ha-caret"></span>
                    </a>
                    <ul class="ha-dropdown-menu">
                        <li><a href="<?php echo home_url('/dissertation'); ?>">Dissertation</a></li>
                        <li><a href="<?php echo home_url('/assignments'); ?>">Assignments</a></li>
                        <li><a href="<?php echo home_url('/case-study'); ?>">Case study</a></li>
                        <li><a href="<?php echo home_url('/research-proposal'); ?>">Research Proposal</a></li>
                        <li><a href="<?php echo home_url('/assignments'); ?>">Online Exam</a></li>
                        <li><a href="<?php echo home_url('/assignments'); ?>">SPSS</a></li>
                        <li><a href="<?php echo home_url('/assignments'); ?>">MAT-LAB</a></li>
                        <li><a href="<?php echo home_url('/assignments'); ?>">Essay</a></li>
                        <li><a href="<?php echo home_url('/assignments'); ?>">Research Data Collection</a></li>
                        <li><a href="<?php echo home_url('/assignments'); ?>">Presentation Service</a></li>
                        <li><a href="<?php echo home_url('/assignments'); ?>">Academic Poster Desiging Services</a></li>
                        <li><a href="<?php echo home_url('/assignments'); ?>">Power Point File</a></li>
                        <li><a href="<?php echo home_url('/coursework'); ?>">Coursework</a></li>
                        <li><a href="<?php echo home_url('/presentations'); ?>">Presentations</a></li>
                    </ul>
                </li>

                <!-- Exams -->
                <li class="ha-has-dropdown">
                    <a href="<?php echo home_url('/online-exams'); ?>">
                        Exams <span class="ha-caret"></span>
                    </a>
                    <ul class="ha-dropdown-menu">
                        <li><a href="<?php echo home_url('/online-exam-help'); ?>">Online Exam Help</a></li>
                        <li><a href="<?php echo home_url('/quiz-help'); ?>">Quiz / Test Help</a></li>
                    </ul>
                </li>

                <!-- Simple links -->
                <li><a href="<?php echo home_url('/topics'); ?>">Topics</a></li>
                <li><a href="<?php echo home_url('/about'); ?>">About</a></li>

                <!-- Order CTA -->
                <li>
                    <a href="<?php echo home_url('/order'); ?>" class="ha-cta-clean">Order Now</a>
                </li>
            </ul>

            <!-- MOBILE MENU BUTTON -->
            <button class="ha-mobile-toggle" aria-label="Toggle Menu">
                <span></span><span></span><span></span>
            </button>
        </nav>
    </div>
</header>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const toggle = document.querySelector(".ha-mobile-toggle");
    const nav = document.querySelector(".ha-nav");

    if (toggle && nav) {
        toggle.addEventListener("click", () => {
            nav.classList.toggle("is-open");
            document.body.classList.toggle("ha-nav-open");
        });
    }

    // Mobile dropdown toggle
    const dropdownParents = document.querySelectorAll(".ha-has-dropdown");

    dropdownParents.forEach(parent => {
        const link = parent.querySelector("a");

        if (!link) return;

        link.addEventListener("click", function (e) {
            if (window.innerWidth <= 900) {
                e.preventDefault(); // stop navigation on mobile
                parent.classList.toggle("is-open-sub");
            }
        });
    });
});
</script>
