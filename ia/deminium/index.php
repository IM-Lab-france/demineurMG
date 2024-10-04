<?php
// index.php
$pluginsDir = '/var/www/html/ia/deminium/plugins';

// Récupérer la liste des IA disponibles
$iaList = array_filter(glob($pluginsDir . '/*'), 'is_dir');
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gestion des IA - Démineur Multijoueur</title>
    <!-- Inclure Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <style>
        /* Optionnel : personnalisation des toasts */
        .toast {
            min-width: 250px;
        }
    </style>
</head>
<body>
    <!-- Conteneur des Toasts -->
    <div aria-live="polite" aria-atomic="true" style="position: fixed; top: 1rem; right: 1rem; min-width: 300px;">
        <div id="toast-container"></div>
    </div>
    
    <div class="container mt-5">
        <h1>Gestion des IA - Démineur Multijoueur</h1>
        <table class="table table-bordered mt-4">
            <thead>
            <tr>
                <th>Nom de l'IA</th>
                <th>Mode Invite</th> <!-- Nouvelle colonne -->
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($iaList as $iaPath): ?>
                <?php
                $iaName = basename($iaPath);
                // Vérifier si les dépendances sont installées
                $initialized = file_exists("$iaPath/env");
                // Vérifier si le processus est en cours d'exécution
                $pidFile = "$iaPath/pid";
                $running = file_exists($pidFile) && posix_kill(file_get_contents($pidFile), 0);
                ?>
                <tr id="ia-row-<?php echo $iaName; ?>">
                    <td><?php echo htmlspecialchars($iaName); ?></td>
                    <td class="text-center">
                        <input type="checkbox" class="invite-checkbox" data-ia="<?php echo $iaName; ?>">
                    </td>
                    <td>
                        <button class="btn btn-primary initialize-btn"
                                data-ia="<?php echo $iaName; ?>"
                                <?php echo $initialized ? 'disabled' : ''; ?>>
                            Initialiser
                        </button>
                        <button class="btn btn-success start-btn"
                                data-ia="<?php echo $iaName; ?>"
                                <?php echo $initialized && !$running ? '' : 'disabled'; ?>>
                            Démarrer
                        </button>
                        <button class="btn btn-danger stop-btn"
                                data-ia="<?php echo $iaName; ?>"
                                <?php echo $running ? '' : 'disabled'; ?>>
                            Arrêter
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Modèle de Toast -->
    <div id="toast-template" class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-delay="5000">
        <div class="toast-header">
            <strong class="mr-auto">Notification</strong>
            <small class="text-muted">Maintenant</small>
            <button type="button" class="ml-2 mb-1 close" data-dismiss="toast" aria-label="Fermer">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <div class="toast-body">
            <!-- Message -->
        </div>
    </div>
    
    <!-- Inclure jQuery et Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.bundle.min.js"></script>
    
    <!-- Script AJAX et Toast -->
    <script>
        $(document).ready(function () {
            // Fonction pour afficher un toast
            function showToast(message, type = 'info') {
                // Clone du modèle de toast
                var $toast = $('#toast-template').clone();
                $toast.removeAttr('id').addClass('bg-' + type + ' text-white');
                $toast.find('.toast-body').text(message);
                
                // Ajout au conteneur
                $('#toast-container').append($toast);
                
                // Initialisation et affichage du toast
                $toast.toast('show');
                
                // Suppression du toast après masquage
                $toast.on('hidden.bs.toast', function () {
                    $(this).remove();
                });
            }

            // Fonction pour récupérer l'état de la checkbox "Mode Invite"
            function getInviteStatus(iaName) {
                return $('#ia-row-' + iaName + ' .invite-checkbox').is(':checked') ? 1 : 0;
            }

            $('.initialize-btn').click(function () {
                var iaName = $(this).data('ia');
                var button = $(this);
                $.ajax({
                    url: 'initialize.php',
                    method: 'POST',
                    data: {iaName: iaName},
                    dataType: 'json',
                    success: function (response) {
                        // Remplacer alert par toast
                        if (response.success) {
                            showToast(response.message, 'success');
                            button.prop('disabled', true);
                            // Activer le bouton "Démarrer"
                            $('#ia-row-' + iaName + ' .start-btn').prop('disabled', false);
                        } else {
                            showToast(response.message, 'danger');
                        }
                    },
                    error: function () {
                        showToast('Erreur lors de l\'initialisation.', 'danger');
                    }
                });
            });

            $('.start-btn').click(function () {
                var iaName = $(this).data('ia');
                var button = $(this);
                var invite = getInviteStatus(iaName);
                $.ajax({
                    url: 'start.php',
                    method: 'POST',
                    data: {iaName: iaName, invite: invite},
                    dataType: 'json',
                    success: function (response) {
                        // Remplacer alert par toast
                        if (response.success) {
                            var mode = invite ? 'avec le mode invite' : 'sans le mode invite';
                            showToast('IA démarrée avec succès ' + mode + '.', 'success');
                            button.prop('disabled', true);
                            // Activer le bouton "Arrêter"
                            $('#ia-row-' + iaName + ' .stop-btn').prop('disabled', false);
                        } else {
                            showToast(response.message, 'danger');
                        }
                    },
                    error: function () {
                        showToast('Erreur lors du démarrage.', 'danger');
                    }
                });
            });

            $('.stop-btn').click(function () {
                var iaName = $(this).data('ia');
                var button = $(this);
                $.ajax({
                    url: 'stop.php',
                    method: 'POST',
                    data: {iaName: iaName},
                    dataType: 'json',
                    success: function (response) {
                        // Remplacer alert par toast
                        if (response.success) {
                            showToast(response.message, 'success');
                            button.prop('disabled', true);
                            // Activer le bouton "Démarrer"
                            $('#ia-row-' + iaName + ' .start-btn').prop('disabled', false);
                        } else {
                            showToast(response.message, 'danger');
                        }
                    },
                    error: function () {
                        showToast('Erreur lors de l\'arrêt.', 'danger');
                    }
                });
            });

            function updateStatus() {
                $.ajax({
                    url: 'status.php',
                    method: 'GET',
                    dataType: 'json',
                    success: function (response) {
                        for (var iaName in response) {
                            var status = response[iaName];
                            var row = $('#ia-row-' + iaName);
                            row.find('.initialize-btn').prop('disabled', status.initialized);
                            row.find('.start-btn').prop('disabled', !status.initialized || status.running);
                            row.find('.stop-btn').prop('disabled', !status.running);
                        }
                    },
                    error: function () {
                        showToast('Erreur lors de la mise à jour du statut.', 'danger');
                    }
                });
            }

            // Actualiser l'état toutes les 5 secondes
            setInterval(updateStatus, 5000);

            // Initialiser les toasts (nécessaire pour certains navigateurs)
            $('.toast').toast({ delay: 5000 });
        });
    </script>
</body>
</html>
