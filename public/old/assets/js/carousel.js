$(function () {
    var owl = $('.testimonials-con .owl-carousel');
    owl.owlCarousel({
        margin: 24,
        nav: false,
        loop: true,
        dots: true,
        autoplay: true,
        autoplayTimeout: 8000,
        responsive: {
            0: {
                items: 1
            },
            576: {
                items: 2
            },
            768: {
                items: 3
            },
            992: {
                items: 4
            },
            1500: {
                items: 6
                //  stagePadding: 50,
            }
        }
    })
})
// js for home page robot comment loading text
const text1 = "Salut, en quoi pouvons-nous t'aider ?";
const text2 = "Pourriez-vous m'aider à créer cette tâche ?";
const element1 = document.getElementById("text1");
const element2 = document.getElementById("text2");

function typeText(text, element, delayBetweenLetters = 50, callback = null) {
    let index = 0;

    function type() {
        if (index < text.length) {
            element.innerHTML += text.charAt(index);
            index++;
            setTimeout(type, delayBetweenLetters);
        } else if (callback) {
            setTimeout(callback, 100); // small pause before next starts
        }
    }
    type();
}

window.onload = function () {
    if (element1 && element2) {
        typeText(text1, element1, 50, () => {
            typeText(text2, element2, 50);
        });
    } else {
        console.warn("One or both elements not found: #text1 or #text2");
    }
};