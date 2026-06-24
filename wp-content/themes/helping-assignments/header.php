<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="p:domain_verify" content="a4f5ddb3ca8bdcebbea95bcdfc774927" />
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>

<!-- TOP INFO BAR -->
<div class="ha-topbar">
    <div class="container ha-topbar-inner">
        <div class="ha-topbar-left">
            🎓 Trusted UK Dissertation & Assignment Writers — <span>24/7 Support</span>
        </div>

        <div class="ha-topbar-right">
            <a href="tel:+447782200976" class="ha-top-link">📞 +44 7782 200976</a>
            <span class="ha-top-sep">•</span>
            <a href="mailto:info@onlinedissertationadvisors.co.uk" class="ha-top-link">✉ info@onlinedissertationadvisors.co.uk</a>
            <!-- <a href="mailto:inquiries@onlinedissertationadvisors.co.uk" class="ha-top-link">✉ inquiries@onlinedissertationadvisors.co.uk</a> -->
            <a href="https://wa.me/447782200976" class="ha-top-whatsapp" target="_blank">
                WhatsApp Chat
            </a>
        </div>
    </div>
</div>

<!-- MAIN HEADER -->
<header class="ha-header">
    <div class="container ha-header-main">

        <!-- LOGO -->
        <div class="ha-logo">
            <a href="<?php echo home_url(); ?>">
                <img
                    src="<?php echo esc_url(get_theme_file_uri('images/oda-logo.webp')); ?>"
                    width="1200"
                    height="628"
                    alt="Online Dissertation Advisors"
                    decoding="async"
                >
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
                        <li><a href="<?php echo home_url('/dissertation/'); ?>">Dissertation</a></li>
                        <li><a href="<?php echo home_url('/assignments'); ?>">Assignments</a></li>
                        <li><a href="<?php echo home_url('/essay'); ?>">Essay</a></li>
                        <li><a href="<?php echo home_url('/case-study'); ?>">Case study</a></li>
                        <li><a href="<?php echo home_url('/coursework'); ?>">Coursework</a></li>
                        <li><a href="<?php echo home_url('/presentation-writing'); ?>">Presentation Writing</a></li>
                        <li><a href="<?php echo home_url('/mat-lab'); ?>">MAT-LAB</a></li>
                        <li><a href="<?php echo home_url('/spss'); ?>">SPSS</a></li>
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

                <li><a href="<?php echo home_url('/about'); ?>">About</a></li>

                <!-- Order CTA (Included in list for mobile consistency) -->
                <li class="ha-nav-cta">
                    <a href="<?php echo home_url('/order'); ?>" class="ha-cta-clean">Order Now</a>
                </li>
            </ul>
        </nav>

        <!-- MOBILE MENU BUTTON -->
        <button class="ha-mobile-toggle" aria-label="Toggle Menu">
            <span></span><span></span><span></span>
        </button>
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
            if (window.innerWidth <= 1100) {
                e.preventDefault(); // stop navigation on mobile
                parent.classList.toggle("is-open-sub");
            }
        });
    });
});
</script>
