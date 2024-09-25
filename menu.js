// Charger le contenu du menu commun
window.onload = function() {
    fetch('menu.html')
        .then(response => response.text())
        .then(data => {
            document.getElementById('menuContainer').innerHTML = data;
        });
};