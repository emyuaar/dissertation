<?php
get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>

<div class="ha-home ha-home-v2">

    <!-- ================= HERO SECTION ================= -->
    <section class="ha-hero-v2">
        <div class="ha-hero-bg">
            <img src="https://onlinedissertationadvisors.co.uk/wp-content/uploads/2025/11/smiling-african-student-pointing-with-pencil-laptop-screen-concentrated-blonde-woman-glasses-propping-chin-with-hand-while-working-with-computer-office-scaled.jpg"
                alt="Student writing Assignment on laptop"
                loading="lazy">
        </div>

        <div class="container">
            <div class="ha-hero-inner-v2">
                <!-- LEFT -->
                <div class="ha-hero-left">
                    <span class="ha-hero-kicker">
                        #1 Assignment Writing Help in the UK
                    </span>

                    <h1>Expert UK Assignment & Dissertation Help</h1>
                    <p class="ha-hero-subtitle">
                        Get your topic approved, your proposal accepted, your assignment completed on time with the help of our team of Master, PhD-qualified academic writers based in the UK. 
                        We will enable you to score better grades through well-structured and fully original academic work in line with the UK university standards.
                    </p>

                    <div class="ha-hero-highlights">
                        <div class="ha-hero-highlight">
                            ⭐ <strong>4.9/5</strong> Average Student Rating
                        </div>
                        <div class="ha-hero-highlight">
                            🔒 100% Confidential & Secure
                        </div>
                        <div class="ha-hero-highlight">
                            🎓 UK University Standards
                        </div>
                    </div>

                    <ul class="ha-hero-bullets">
                        <li>Suggests free topics and outline on advance basis.</li>
                        <li>Refining the topics and writing research questions.</li>
                        <li>No pre-payment consultation fees.</li>
                        <li>Infinite changes under the project scope.</li>
                    </ul>

                    <div class="ha-hero-cta-row">
                        <a href="<?php echo home_url('/order'); ?>" class="ha-hero-big-cta">
                            Get Free Proposal Quote
                            <span>→</span>
                        </a>
                        <!-- <div class="ha-hero-trust">
                            <span class="dot"></span> No upfront payment for consultation.
                        </div> -->
                    </div>
                </div>

                <!-- RIGHT: LEAD FORM -->
                <div class="ha-hero-right">
                    <div class="ha-hero-form-wrap">
                        <h3 class="ha-hero-form-title">Get Quick Help by 650+ PhD Experts</h3>
                        <p class="ha-hero-form-sub">
                            Share your requirements and receive a personalised price quote within minutes.
                        </p>

                        <?php if ( isset( $_GET['enquiry_success'] ) ) : ?>
                            <div class="ha-alert-success">
                                Thank you! Your enquiry has been submitted. We will contact you shortly.
                            </div>
                        <?php endif; ?>

                        <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="POST" class="ha-hero-form">
                            <input type="hidden" name="action" value="ha_submit_order">
                            <input type="hidden" name="ha_type" value="enquiry">

                            <input type="text" name="name" class="ha-form-input" placeholder="Full Name" required>
                            <input type="email" name="email" class="ha-form-input" placeholder="Email Address" required>
                            <input type="tel" name="phone" class="ha-form-input" placeholder="WhatsApp / Phone" required>

                            <select name="service_type" class="ha-form-input">
                                <option value="">Select Service Type</option>
                                <option value="dissertation_proposal">Assignment</option>
                                <option value="full_dissertation">Full Dissertation</option>
                                <option value="assignment">Assignment / Coursework</option>
                                <option value="editing">Editing & Proofreading</option>
                            </select>

                            <textarea name="requirements" class="ha-form-input ha-form-textarea" rows="3"
                                      placeholder="Briefly describe your topic / requirements"></textarea>

                            <button type="submit" class="ha-form-btn">
                                Request Free Consultation
                            </button>

                            <div class="ha-form-footnote">
                                We respect your privacy. Your details will not be shared with any third party.
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- 3 STEP STRIP -->
            <div class="ha-steps-strip">
                <div class="ha-step-item">
                    <div class="ha-step-number">01</div>
                    <h4>Share Your Requirements</h4>
                    <p>Submit your topic, level, word count and deadline using the form above.</p>
                </div>
                <div class="ha-step-item">
                    <div class="ha-step-number">02</div>
                    <h4>Get a Custom Price & Plan</h4>
                    <p>We send a tailored quote along with chapter-wise delivery milestones.</p>
                </div>
                <div class="ha-step-item">
                    <div class="ha-step-number">03</div>
                    <h4>Writer Starts Your Proposal</h4>
                    <p>Once confirmed, a subject expert begins working and shares draft updates.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= TRUST RIBBON ================= -->
    <!-- <section class="ha-trust-ribbon">
        <div class="container">
            <div class="ha-trust-grid">
                <div class="ha-trust-item">
                    <span>✔</span>
                    <div>
                        <strong>100% Original Work</strong>
                        <p>Every proposal is written from scratch based on your guidelines.</p>
                    </div>
                </div>
                <div class="ha-trust-item">
                    <span>✔</span>
                    <div>
                        <strong>Plagiarism-Free Guarantee</strong>
                        <p>Optional Turnitin report available for complete peace of mind.</p>
                    </div>
                </div>
                <div class="ha-trust-item">
                    <span>✔</span>
                    <div>
                        <strong>UK Referencing Standards</strong>
                        <p>Harvard, APA, MLA, OSCOLA and more – as per university rules.</p>
                    </div>
                </div>
                <div class="ha-trust-item">
                    <span>✔</span>
                    <div>
                        <strong>Friendly Support</strong>
                        <p>Student-first support team available via WhatsApp, email & calls.</p>
                    </div>
                </div>
            </div>
        </div>
    </section> -->

    <!-- Elementor editable area (keep this for WP editor) -->
    <div class="ha-elementor-wrap">
        <?php the_content(); ?>
    </div>

</div><!-- /.ha-home -->

<?php
endwhile; endif;

get_footer();
?>
