<?php
/* Template Name: Order Page */
get_header();
?>

<section class="ha-order-section">
    <div class="ha-order-container container">
        <div class="ha-order-header">
            <h1>Fill Out The Order Form</h1>
            <p>Share your requirements and a specialist will contact you with a quote and payment link.</p>
        </div>

        <form id="orderForm"
              class="ha-order-form"
              action="<?php echo admin_url('admin-post.php'); ?>"
              method="POST">
            <input type="hidden" name="action" value="ha_submit_order">
            <input type="hidden" name="ha_type" value="order">

            <!-- Personal Information -->
            <div class="ha-form-section">
                <h3>Personal Information</h3>
                <div class="ha-form-row">
                    <div class="ha-form-group">
                        <label for="name">Your Name*</label>
                        <input type="text" id="name" name="name" required placeholder="Enter your name">
                    </div>
                    <div class="ha-form-group">
                        <label for="email">Your Email*</label>
                        <input type="email" id="email" name="email" required placeholder="Enter your email">
                    </div>
                    <div class="ha-form-group">
                        <label for="phone">Phone Number*</label>
                        <input type="tel" id="phone" name="phone" required placeholder="Enter your phone number">
                    </div>
                </div>
            </div>

            <!-- Academic Details -->
            <div class="ha-form-section">
                <h3>Academic Details</h3>
                <div class="ha-form-row">
                    <div class="ha-form-group">
                        <label for="education">Education Level</label>
                        <select id="education" name="education">
                            <option value="Under Graduate">Under Graduate</option>
                            <option value="Graduate">Graduate</option>
                            <option value="Masters">Masters</option>
                            <option value="PhD">PhD</option>
                        </select>
                    </div>
                    <div class="ha-form-group">
                        <label for="subject">Academic Subject</label>
                        <select id="subject" name="subject">
                            <option value="Accounting">Accounting</option>
                            <option value="Business">Business</option>
                            <option value="Economics">Economics</option>
                            <option value="Finance">Finance</option>
                            <option value="Human Resource Management">Human Resource Management</option>
                            <option value="Management">Management</option>
                            <option value="Marketing">Marketing</option>
                            <option value="Nursing">Nursing</option>
                            <option value="Psychology">Psychology</option>
                            <option value="Law">Law</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="ha-form-group">
                        <label for="paperType">Paper Type</label>
                        <select id="paperType" name="paperType">
                            <option value="Dissertation">Dissertation</option>
                            <option value="Dissertation Proposal">Dissertation Proposal</option>
                            <option value="Essay">Essay</option>
                            <option value="Assignment">Assignment</option>
                            <option value="Research Paper">Research Paper</option>
                            <option value="Thesis">Thesis</option>
                            <option value="Case Study">Case Study</option>
                            <option value="Coursework">Coursework</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Paper Requirements -->
            <div class="ha-form-section">
                <h3>Paper Requirements</h3>
                <div class="ha-form-row">
                    <div class="ha-form-group">
                        <label for="pages">Number of Pages*</label>
                        <input type="number" id="pages" name="pages" min="1" value="1" required>
                    </div>
                    <div class="ha-form-group">
                        <label for="wordCount">Word Count (Approx)</label>
                        <input type="text" id="wordCount" name="wordCount" value="250">
                    </div>
                    <div class="ha-form-group">
                        <label for="quality">Paper Quality</label>
                        <select id="quality" name="quality">
                            <option value="2:2 Class">2:2 Class</option>
                            <option value="2:1 Class">2:1 Class</option>
                            <option value="1st Class">1st Class</option>
                        </select>
                    </div>
                </div>

                <div class="ha-form-row">
                    <div class="ha-form-group">
                        <label for="delivery">Delivery Time</label>
                        <select id="delivery" name="delivery">
                            <option value="24 Hours">24 Hours</option>
                            <option value="2 Days">2 Days</option>
                            <option value="3 Days">3 Days</option>
                            <option value="4-5 Days">4-5 Days</option>
                            <option value="6-10 Days">6-10 Days</option>
                            <option value="11-15 Days">11-15 Days</option>
                            <option value="More than 15 Days">More than 15 Days</option>
                        </select>
                    </div>
                    <div class="ha-form-group">
                        <label for="citation">Citation Style</label>
                        <select id="citation" name="citation">
                            <option value="APA">APA</option>
                            <option value="MLA">MLA</option>
                            <option value="Harvard">Harvard</option>
                            <option value="Chicago">Chicago</option>
                            <option value="Oxford">Oxford</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="ha-form-row">
                    <div class="ha-form-group ha-full-width">
                        <label for="topic">Topic*</label>
                        <input type="text" id="topic" name="topic" required placeholder="Enter your paper topic">
                    </div>
                </div>

                <div class="ha-form-row">
                    <div class="ha-form-group ha-full-width">
                        <label for="instructions">Paper Description / Instructions</label>
                        <textarea id="instructions"
                                  name="instructions"
                                  rows="5"
                                  placeholder="Share any specific guidelines, learning outcomes, marking criteria, or resources to use..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Submit (no payment) -->
            <div class="ha-order-summary">
                <button type="submit" class="ha-order-submit-btn">
                    Submit Order
                </button>
                <p class="ha-order-note">
                    No payment is taken on this form. A coordinator will review your details and get back to you with the best price and next steps.
                </p>
            </div>

        </form>
    </div>
</section>

<style>
    .ha-order-section {
        background: #f9fafb;
        padding: 60px 0 70px;
    }

    .ha-order-container {
        max-width: 960px;
        margin: 0 auto;
    }

    .ha-order-header {
        margin-bottom: 24px;
    }

    .ha-order-header h1 {
        font-size: 2.2rem;
        margin: 0 0 6px;
        color: #111827;
        font-weight: 700;
        letter-spacing: 0.01em;
    }

    .ha-order-header p {
        margin: 0;
        color: #6b7280;
        font-size: 0.98rem;
    }

    .ha-order-form {
        background: #ffffff;
        border-radius: 18px;
        padding: 28px 28px 30px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, 0.10);
    }

    .ha-form-section + .ha-form-section {
        margin-top: 26px;
        padding-top: 22px;
        border-top: 1px solid #e5e7eb;
    }

    .ha-form-section h3 {
        font-size: 1.15rem;
        margin: 0 0 14px;
        color: #111827;
        font-weight: 600;
    }

    .ha-form-row {
        display: flex;
        flex-wrap: wrap;
        gap: 18px;
    }

    .ha-form-group {
        flex: 1 1 0;
        min-width: 0;
        display: flex;
        flex-direction: column;
    }

    .ha-form-group.ha-full-width {
        flex: 0 0 100%;
    }

    .ha-form-group label {
        font-size: 0.9rem;
        margin-bottom: 5px;
        color: #374151;
        font-weight: 500;
    }

    .ha-form-group input,
    .ha-form-group select,
    .ha-form-group textarea {
        border-radius: 10px;
        border: 1px solid #d1d5db;
        padding: 10px 12px;
        font-size: 0.95rem;
        outline: none;
        transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        background: #f9fafb;
    }

    .ha-form-group textarea {
        resize: vertical;
        min-height: 120px;
    }

    .ha-form-group input:focus,
    .ha-form-group select:focus,
    .ha-form-group textarea:focus {
        border-color: #f59e0b;
        box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.25);
        background: #ffffff;
    }

    .ha-form-group input[readonly] {
        background: #f3f4f6;
        cursor: default;
    }

    .ha-order-summary {
        margin-top: 26px;
        padding-top: 20px;
        border-top: 1px solid #e5e7eb;
        display: flex;
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }

    .ha-order-submit-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 32px;
        height: 48px;
        border-radius: 999px;
        border: none;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        background: linear-gradient(135deg, #facc15, #f59e0b);
        color: #111827;
        box-shadow: 0 12px 30px rgba(245, 158, 11, 0.45);
        transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    .ha-order-submit-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 16px 40px rgba(245, 158, 11, 0.55);
        background: linear-gradient(135deg, #fbbf24, #f59e0b);
    }

    .ha-order-submit-btn:active {
        transform: translateY(0);
        box-shadow: 0 8px 20px rgba(245, 158, 11, 0.45);
    }

    .ha-order-note {
        margin: 0;
        font-size: 0.85rem;
        color: #6b7280;
    }

    @media (max-width: 768px) {
        .ha-order-section {
            padding: 40px 0 50px;
        }
        .ha-order-form {
            padding: 20px 16px 22px;
            border-radius: 14px;
        }
        .ha-form-row {
            flex-direction: column;
        }
        .ha-order-summary {
            align-items: stretch;
        }
        .ha-order-submit-btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<!-- <script>
    // Auto-adjust word count based on number of pages (approx. 250 words/page)
    document.addEventListener('DOMContentLoaded', function () {
        var pagesInput = document.getElementById('pages');
        var wordCountInput = document.getElementById('wordCount');

        if (pagesInput && wordCountInput) {
            var updateWordCount = function () {
                var pages = parseInt(pagesInput.value, 10) || 0;
                wordCountInput.value = pages > 0 ? pages * 250 : '';
            };
            pagesInput.addEventListener('input', updateWordCount);
            updateWordCount();
        }
    });
</script> -->

<?php get_footer(); ?>
