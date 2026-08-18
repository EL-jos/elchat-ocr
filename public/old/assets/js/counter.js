$(function () {
    $('.counter').each(function () {
        $(this).prop('Counter', 0).animate({
            Counter: $(this).text()
        }, {
            duration: 4000,
            easing: 'swing',

            step: function (now) {
                $(this).text(Math.ceil(now));
            }
        });
    });
    $(document).on('hover', '.card-container', function () {
        $('.card-container').removeClass('active');
        $(this).addClass('active');
    });
});

// wow js
new WOW().init();


// comingsoon page countdown js
$(function () {
    if (document.getElementById("days") !== null) {
        const second = 1000,
            minute = second * 60,
            hour = minute * 60,
            day = hour * 24;

        let today = new Date(),
            dd = String(today.getDate()).padStart(2, "0"),
            mm = String(today.getMonth() + 1).padStart(2, "0"),
            yyyy = today.getFullYear(),
            nextYear = '2025',
            dayMonth = "9/24/",
            birthday = dayMonth + yyyy;

        today = mm + "/" + dd + "/" + yyyy;
        if (today > birthday) {
            birthday = dayMonth + nextYear;
        }
        //end

        const countDown = new Date(birthday).getTime(),
            x = setInterval(function () {
                const now = new Date().getTime(),
                    distance = countDown - now;

                let days = Math.floor(distance / (day));
                let hours = Math.floor((distance % (day)) / (hour));
                let minutes = Math.floor((distance % (hour)) / (minute));
                let seconds = Math.floor((distance % (minute)) / second);

                document.getElementById("days").innerText = days,
                    document.getElementById("hours").innerText = hours,
                    document.getElementById("minutes").innerText = minutes,
                    document.getElementById("seconds").innerText = seconds;

                //do something later when date is reached
                if (distance < 0) {
                    clearInterval(x);
                    var items = document.querySelectorAll(".compaign_countdown");
                    for (var i = 0; i <= items.length; i++) {
                        if (typeof items[i] !== 'undefined') {
                            items[i].style.display = "none";
                        }
                    }
                }
                //seconds
            }, 0)
    }
}());