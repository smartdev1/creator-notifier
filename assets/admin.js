jQuery(document).ready(function ($) {
  function debugLog(message, type = "info") {
    var timestamp = new Date().toLocaleTimeString();
    var styles = {
      info: "color: #74c0fc;",
      success: "color: #51cf66;",
      warning: "color: #ffd43b;",
      error: "color: #ff6b6b;",
    };

    var style = styles[type] || styles["info"];
    console.log("%c[" + timestamp + "] " + message, style);
  }

  function parseServerError(xhr, status, error) {
    debugLog("=== ANALYSE ERREUR SERVEUR ===", "error");

    debugLog("Status HTTP: " + xhr.status, "error");
    debugLog("Status AJAX: " + status, "error");
    debugLog("Erreur: " + error, "error");

    debugLog("Headers réponse:", "error");
    var headers = xhr.getAllResponseHeaders();
    if (headers) {
      headers.split("\n").forEach(function (header) {
        if (header.trim()) debugLog("  " + header.trim(), "error");
      });
    }

    debugLog("Réponse brute:", "error");
    debugLog(xhr.responseText, "error");

    try {
      var response = JSON.parse(xhr.responseText);

      if (response.data && typeof response.data === "object") {
        debugLog("Données erreur WordPress:", "error");
        for (var key in response.data) {
          if (response.data.hasOwnProperty(key)) {
            debugLog("  " + key + ": " + response.data[key], "error");
          }
        }
      }

      if (response.code) {
        debugLog("Code erreur: " + response.code, "error");
      }

      if (response.additional_errors) {
        debugLog("Erreurs additionnelles:", "error");
        response.additional_errors.forEach(function (err, index) {
          debugLog("  [" + index + "] " + JSON.stringify(err), "error");
        });
      }
    } catch (e) {
      debugLog("Réponse non-JSON ou erreur parsing: " + e.message, "error");

      if (
        xhr.responseText.includes("fatal error") ||
        xhr.responseText.includes("PHP Error")
      ) {
        debugLog(" Erreur PHP détectée dans la réponse", "error");
        var errorLines = xhr.responseText.split("\n").filter(function (line) {
          return (
            line.includes("error") ||
            line.includes("Error") ||
            line.includes("Exception")
          );
        });
        errorLines.forEach(function (line) {
          debugLog("  " + line.trim(), "error");
        });
      }
    }

    debugLog("=== FIN ANALYSE ERREUR ===", "error");
  }

  debugLog("MP Creator Admin JS chargé", "success");
  debugLog("URL AJAX: " + mp_creator_ajax.ajax_url, "info");
  debugLog(
    "Nonce: " + (mp_creator_ajax.nonce ? " Présent" : " Manquant"),
    mp_creator_ajax.nonce ? "success" : "error",
  );

  $("#mp-creator-form").on("submit", function (e) {
    e.preventDefault();

    debugLog("=== DÉBUT TRAITEMENT FORMULAIRE ===", "info");

    var formData = $(this).serializeArray();
    var isEdit = $('input[name="id"]').length > 0;

    var name = $("#name").val().trim();
    var email = $("#email").val().trim();
    var brand_slug = $("#brand_slug").val();

    debugLog("Validation côté client:", "info");
    debugLog(
      "  - Nom: " + (name ? " Rempli" : " Vide"),
      name ? "success" : "error",
    );
    debugLog(
      "  - Email: " + (email ? " Rempli" : " Vide"),
      email ? "success" : "error",
    );
    debugLog(
      "  - Marque: " + (brand_slug ? " Sélectionnée" : " Non sélectionnée"),
      brand_slug ? "success" : "error",
    );

    if (!name || !email || !brand_slug) {
      debugLog(" Validation échouée - champs requis manquants", "error");
      showMessage("Veuillez remplir tous les champs obligatoires.", "error");
      return;
    }

    if (!isValidEmail(email)) {
      debugLog(" Email invalide: " + email, "error");
      showMessage("Veuillez entrer une adresse email valide.", "error");
      return;
    }

    debugLog(" Validation côté client réussie", "success");

    debugLog("Données du formulaire:", "info");
    formData.forEach(function (field) {
      debugLog("  - " + field.name + ": " + (field.value || "(vide)"), "info");
    });

    debugLog("Action: " + (isEdit ? "MODIFICATION" : "CRÉATION"), "info");

    var ajaxData = {
      action: isEdit ? "update_creator" : "create_creator",
      nonce: mp_creator_ajax.nonce,
    };

    formData.forEach(function (field) {
      ajaxData[field.name] = field.value;
    });

    debugLog("Données AJAX préparées:", "info");
    debugLog("  - Action: " + ajaxData.action, "info");
    debugLog(
      "  - Nonce: " + (ajaxData.nonce ? " Présent" : " Manquant"),
      ajaxData.nonce ? "success" : "error",
    );

    $.ajax({
      url: mp_creator_ajax.ajax_url,
      type: "POST",
      data: ajaxData,
      dataType: "json",
      timeout: 30000,

      beforeSend: function (xhr) {
        debugLog("Envoi de la requête AJAX...", "info");
        debugLog("  - URL: " + mp_creator_ajax.ajax_url, "info");
        debugLog("  - Méthode: POST", "info");
        debugLog("  - Timeout: 30s", "info");
      },

      success: function (response, status, xhr) {
        debugLog(" Réponse AJAX reçue avec succès", "success");
        debugLog(
          "Status: " + (response.valid ? "SUCCÈS" : "ÉCHEC"),
          response.valid ? "success" : "error",
        );
        debugLog(
          "Message serveur: " + response.message,
          response.valid ? "success" : "error",
        );

        if (response.creator_id) {
          debugLog("ID créateur: " + response.creator_id, "success");
        }

        if (response.valid) {
          showMessage(response.message, "success");
          debugLog(" Opération réussie!", "success");

          if (!isEdit) {
            $("#mp-creator-form")[0].reset();
            debugLog(
              "📝 Formulaire réinitialisé pour nouvelle création",
              "info",
            );
          }

          setTimeout(function () {
            debugLog(" Rechargement de la page...", "info");
            window.location.reload();
          }, 1500);
        } else {
          showMessage(response.message, "error");
          debugLog(" Échec de l'opération: " + response.message, "error");

          if (response.errors) {
            debugLog("Erreurs détaillées:", "error");
            for (var errorKey in response.errors) {
              debugLog(
                "  - " + errorKey + ": " + response.errors[errorKey],
                "error",
              );
            }
          }
        }
      },

      error: function (xhr, status, error) {
        debugLog(" ERREUR AJAX CRITIQUE", "error");

        parseServerError(xhr, status, error);

        var userMessage = "Une erreur est survenue. ";

        if (xhr.status === 0) {
          userMessage += "Problème de connexion réseau.";
          debugLog("🔌 Vérifiez la connexion internet", "error");
        } else if (xhr.status === 403) {
          userMessage += "Accès refusé (nonce invalide).";
          debugLog("🔑 Nonce peut être expiré, rechargez la page", "error");
        } else if (xhr.status === 404) {
          userMessage += "URL AJAX non trouvée.";
          debugLog(
            "🌐 Vérifiez l'URL AJAX: " + mp_creator_ajax.ajax_url,
            "error",
          );
        } else if (xhr.status === 500) {
          userMessage += "Erreur interne du serveur.";
          debugLog("⚙️ Vérifiez les logs PHP/WordPress", "error");
        } else if (status === "timeout") {
          userMessage += "Timeout de la requête.";
          debugLog(" La requête a pris trop de temps", "error");
        } else if (status === "parsererror") {
          userMessage += "Erreur de parsing de la réponse.";
          debugLog(" La réponse n'est pas du JSON valide", "error");
        }

        showMessage(userMessage, "error");
      },

      complete: function (xhr, status) {
        debugLog(
          "=== FIN TRAITEMENT FORMULAIRE - Status: " + status + " ===",
          status === "success" ? "success" : "error",
        );
      },
    });
  });

  $(".mp-delete-creator").on("click", function () {
    var creatorId = $(this).data("id");
    var creatorName = $(this).closest("tr").find("td:first").text().trim();

    debugLog(" Tentative de suppression du créateur:", "warning");
    debugLog("  - ID: " + creatorId, "warning");
    debugLog("  - Nom: " + creatorName, "warning");

    if (
      !confirm(mp_creator_ajax.confirm_delete + "\n\nCréateur: " + creatorName)
    ) {
      debugLog(" Suppression annulée par l'utilisateur", "warning");
      return;
    }

    var button = $(this);

    debugLog(
      " Confirmation reçue, envoi de la requête de suppression...",
      "info",
    );

    $.ajax({
      url: mp_creator_ajax.ajax_url,
      type: "POST",
      data: {
        action: "delete_creator",
        nonce: mp_creator_ajax.nonce,
        id: creatorId,
      },
      dataType: "json",
      timeout: 15000,

      beforeSend: function () {
        debugLog("Envoi requête suppression...", "info");
      },

      success: function (response) {
        debugLog(
          "Réponse suppression reçue:",
          response.valid ? "success" : "error",
        );
        debugLog(
          "Statut: " + (response.valid ? "SUCCÈS" : "ÉCHEC"),
          response.valid ? "success" : "error",
        );
        debugLog(
          "Message: " + response.message,
          response.valid ? "success" : "error",
        );

        if (response.valid) {
          showMessage(response.message, "success");
          button.closest("tr").fadeOut(400, function () {
            $(this).remove();
            debugLog(
              " Créateur supprimé avec succès de l'interface",
              "success",
            );
          });
        } else {
          showMessage(response.message, "error");
          debugLog(" Échec suppression: " + response.message, "error");
        }
      },

      error: function (xhr, status, error) {
        debugLog(" ERREUR lors de la suppression:", "error");
        parseServerError(xhr, status, error);
        showMessage("Erreur lors de la suppression.", "error");
      },
    });
  });

  function isValidEmail(email) {
    var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
  }

  function showMessage(message, type) {
    var icon =
      type === "success"
        ? ""
        : type === "error"
          ? ""
          : type === "warning"
            ? ""
            : "";

    $("#mp-creator-messages").html(
      '<div class="mp-creator-message ' +
        type +
        '">' +
        "<strong>" +
        icon +
        " " +
        message +
        "</strong>" +
        "</div>",
    );

    setTimeout(function () {
      $("#mp-creator-messages").empty();
    }, 5000);
  }

  debugLog("=== INITIALISATION PAGE ADMIN MP CREATOR ===", "success");
  debugLog("URL actuelle: " + window.location.href, "info");
  debugLog(
    "Nombre de créateurs dans le tableau: " +
      $(".mp-creator-list tbody tr").length,
    "info",
  );
  debugLog(
    "Formulaire détecté: " + ($("#mp-creator-form").length ? " Oui" : " Non"),
    $("#mp-creator-form").length ? "success" : "error",
  );

  var submitBtn = $('#mp-creator-form button[type="submit"]');
  if (submitBtn.length) {
    debugLog(
      "Bouton submit: " +
        (submitBtn.prop("disabled") ? " Désactivé" : " Activé"),
      submitBtn.prop("disabled") ? "warning" : "success",
    );
  }
});

window.addEventListener("error", function (e) {
  console.error(
    "%c[MP Creator] ERREUR JAVASCRIPT GLOBALE:",
    "color: #ff6b6b; font-weight: bold;",
  );
  console.error("Message:", e.message);
  console.error("Fichier:", e.filename);
  console.error("Ligne:", e.lineno);
  console.error("Colonne:", e.colno);
  console.error("Erreur:", e.error);
});

window.addEventListener("unhandledrejection", function (e) {
  console.error(
    "%c[MP Creator] PROMESSE NON CAPTURÉE:",
    "color: #ff6b6b; font-weight: bold;",
  );
  console.error("Raison:", e.reason);
});
