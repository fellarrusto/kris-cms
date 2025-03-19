$(document).ready(function () {

    /* **** sticky **** */
    $(window).scroll(function () {
        if ($(this).scrollTop() > 150) {
            $("header").addClass("nav-new");
        } else {
            $("header").removeClass("nav-new");
        }
    });
    /* **** sticky **** */

    /* **** scrollIt ***** */
    $(function () {
        $.scrollIt({
            upKey: 38,
            downKey: 40,
            easing: "linear",
            scrollTime: 600,
            activeClass: "active",
            onPageChange: null,
            topOffset: -50,
        });
    });
    /* **** End scrollIt ***** */

    /* **** Navigation Toggle Start **** */
    $(".navbar-collapse a").click(function () {
        $(".navbar-collapse").collapse("hide");
    });
    /* **** Navigation Toggle End **** */
    
    $(".navbar-toggler").on("click", function () {
        $("body").toggleClass("add-fix");
    });
    
    /* Scroll Down to Contact Form */
    $('.scroll-btn').on('click', function(){
        $('html, body').animate({
        scrollTop: $(".about-wrp").offset().top - 80
        }, 1000);
    });

    /* **** Slider ***** */
    $(".dishes-slider").slick({
        arrows: false,
        loop: true,
        dots: true,
        autoplay: true,
        autoplaySpeed: 1500,
        speed: 1000,
        infinite: true,
        fade:true,
        slidesToShow: 1,
        slidesToScroll: 1,
    });
    /* ***** End Slider **** */


    /* **** Language Selection ***** */
    var params = new URLSearchParams(window.location.search);
    var lang = params.get("ln") || "it"; // Default to "it" if not found

    $(".language-rw li").removeClass("active");
    $(".language-rw li a").each(function () {
        if ($(this).text().trim() === lang) {
            $(this).parent().addClass("active");
        }
    });

    $(".language-rw li a").click(function (e) {
        e.preventDefault();
        var selectedLang = $(this).text().trim();
        params.set("ln", selectedLang);
        window.location.search = params.toString();
    });
    /* **** End Language Selection ***** */
});
