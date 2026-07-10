$(function () {
	$("#contactpage").validate({
		rules: {
			fname: { required: true, minlength: 2 },
			phone: { required: true, minlength: 6 },
			email: { required: true, email: true },
			msg: { required: true, minlength: 10 }
		},
		messages: {
			fname: {
				required: "Veuillez saisir votre nom.",
				minlength: "Le nom doit contenir au moins 2 caractères."
			},
			phone: {
				required: "Veuillez saisir votre téléphone.",
				minlength: "Numéro de téléphone invalide."
			},
			email: {
				required: "Veuillez saisir votre email.",
				email: "Adresse email invalide."
			},
			msg: {
				required: "Veuillez saisir votre message.",
				minlength: "Votre message doit contenir au moins 10 caractères."
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
		$button.html('<i class="fas fa-spinner fa-spin"></i> Envoi...');
		$result.hide().html("");

		$.ajax({
			url: "/contact/send",
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
					html = "Votre session a expiré. Veuillez recharger la page.";
				} else if (xhr.responseJSON && xhr.responseJSON.message) {
					html = xhr.responseJSON.message;
				} else {
					html = "Une erreur est survenue. Veuillez réessayer.";
				}

				$result.html(
					'<div style="display: flex;" class="alert alert-danger">' + html + '</div>'
				).fadeIn();
			},
			complete: function () {
				$button.prop("disabled", false);
				$button.html('Envoyer <i class="fas fa-arrow-right ml-2"></i>');
			}
		});
	}
});