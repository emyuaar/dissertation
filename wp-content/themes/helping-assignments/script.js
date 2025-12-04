document.addEventListener('DOMContentLoaded', function () {

    // Mobile Menu Toggle
    const mobileBtn = document.querySelector('.mobile-menu-toggle');
    const nav = document.querySelector('.main-nav');

    if (mobileBtn) {
        mobileBtn.addEventListener('click', () => {
            nav.classList.toggle('active');
        });
    }

    // FAQ Accordion
    const faqQuestions = document.querySelectorAll('.faq-question');
    faqQuestions.forEach(question => {
        question.addEventListener('click', () => {
            const item = question.parentElement;
            item.classList.toggle('active');
        });
    });

    // Order Form Logic
    const pagesInput = document.getElementById('pages');
    const wordCountInput = document.getElementById('wordCount');
    const priceDisplay = document.getElementById('totalPrice');

    if (pagesInput && wordCountInput) {
        pagesInput.addEventListener('input', updateOrderDetails);

        function updateOrderDetails() {
            const pages = parseInt(pagesInput.value) || 0;
            const words = pages * 250;
            wordCountInput.value = words;

            // Simple price calculation for demo purposes
            // Base price £26 per page (example)
            const basePrice = 26;
            const total = pages * basePrice;
            if (priceDisplay) {
                priceDisplay.textContent = `£${total.toFixed(2)}`;
            }
        }
    }

    // Form Submission
    // const orderForm = document.getElementById('orderForm');
    // if (orderForm) {
    //     orderForm.addEventListener('submit', (e) => {
    //         // e.preventDefault(); // Removed to allow PHP submission
    //         // alert('Thank you for your order! This is a demo site, so no payment will be processed.');
    //     });
    // }
});
