$(function () {
	const i18n = window.elchatContactI18n || {};
	const validation = i18n.validation || {};

	$("#contactpage").validate({
		rules: {
			fname: { required: true, minlength: 2 },
			phone: { required: true, minlength: 6 },
			email: { required: true, email: true },
			msg: { required: true, minlength: 10 }
		},
		messages: {
			fname: {
				required: validation.name_required || "Please enter your name.",
				minlength: validation.name_min || "Your name is too short."
			},
			phone: {
				required: validation.phone_required || "Please enter your phone number.",
				minlength: validation.phone_min || "Invalid phone number."
			},
			email: {
				required: validation.email_required || "Please enter your email.",
				email: validation.email_invalid || "Invalid email address."
			},
			msg: {
				required: validation.message_required || "Please enter your message.",
				minlength: validation.message_min || "Your message must contain at least 10 characters."
			}
		},
		errorElement: "span",
		errorPlacement: function (error, element) {
			error.appendTo(element.parent());
		},

		// ✅ IMPORTANT :接收 le formulaire en paramètre
		submitHandler: function (form) {
			sendContact(form);
			return false; // ✅ Empêche la soumission classique
		}
	});

	function sendContact(form) {
		let $form = $(form);
		let $button = $("#submitBtn");
		let $result = $("#form_result");

		// Récupérer le token CSRF
		let csrfToken = $('meta[name="csrf-token"]').attr('content')
			|| $form.find('input[name="_token"]').val();

		$button.prop("disabled", true);
		$button.html('<i class="fas fa-spinner fa-spin"></i> ' + (i18n.sending || 'Sending...'));
		$result.hide().html("");

		$.ajax({
			url: $form.data("contact-url") || "/contact/send",
			method: "POST",
			data: $form.serialize(),
			dataType: "json",
			headers: {
				'X-CSRF-TOKEN': csrfToken
			},
			success: function (response) {
				$result.html(
					'<div style="display: flex;" class="alert alert-success">' +
						response.message +
					'</div>'
				).fadeIn();
				form.reset();
			},
			error: function (xhr) {
				let html = "";

				if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
					$.each(xhr.responseJSON.errors, function (key, value) {
						html += "<div>" + value[0] + "</div>";
					});
				} else if (xhr.status === 419) {
					html = i18n.expired || "Your session has expired. Please reload the page.";
				} else if (xhr.responseJSON && xhr.responseJSON.message) {
					html = xhr.responseJSON.message;
				} else {
					html = i18n.error || "Something went wrong. Please try again.";
				}

				$result.html(
					'<div style="display: flex;" class="alert alert-danger">' + html + '</div>'
				).fadeIn();
			},
			complete: function () {
				$button.prop("disabled", false);
				$button.html((i18n.send || 'Send') + ' <i class="fas fa-arrow-right ml-2"></i>');
			}
		});
	}
});
