jQuery(document).ready(function ($) {
    
    const slideCount = 1;
    var all_carousels = $('.carousel-container');
    all_carousels.each(function(index, element) {
        showSlide($(element));
    });

    function showSlide(targetDiv, slideIndex = 0) {
        
        const slides = targetDiv.find('.carousel .carousel-item');
        const totalItems = slides.length;

        if (totalItems <= slideCount) {
            slideIndex = 0;
            targetDiv.find('.carousel-nav').hide();
        } else {
            targetDiv.find('.carousel-nav').show();

            if (slideIndex <= 0) {
                slideIndex = 0;
                targetDiv.find('.carousel-nav .slider-prev').css({
                    'pointer-events': 'none',
                    'opacity': '0.5'
                });
            } else {
                targetDiv.find('.carousel-nav .slider-prev').css({
                    'pointer-events': 'auto',
                    'opacity': '1'
                });
            }

            if (slideIndex >= totalItems - slideCount) {
                slideIndex = totalItems - slideCount;
                targetDiv.find('.carousel-nav .slider-next').css({
                    'pointer-events': 'none',
                    'opacity': '0.5'
                });
            } else {
                targetDiv.find('.carousel-nav .slider-next').css({
                    'pointer-events': 'auto',
                    'opacity': '1'
                });
            }
        }

        targetDiv.find('.carousel-nav').attr('data-index', slideIndex);
        targetDiv.find('.carousel').css('transform', `translateX(-${slideIndex * (100 / slideCount)}%)`);
    }

    $('.carousel-nav .slider-next').on('click', function() {

        let slideIndex = parseInt($(this).closest('.carousel-nav').attr('data-index'));
        slideIndex++;
        showSlide($(this).closest('.carousel-container'), slideIndex);
    });

    $('.carousel-nav .slider-prev').on('click', function() {

        let slideIndex = parseInt($(this).closest('.carousel-nav').attr('data-index'));
        slideIndex--;
        showSlide($(this).closest('.carousel-container'), slideIndex);
    });
});
