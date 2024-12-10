/*=============== SHOW MENU ===============*/
const showMenu = (toggleId, navId) => {
    const toggle = document.getElementById(toggleId),
          nav = document.getElementById(navId);
 
    toggle.addEventListener('click', () => {
        // Add show-menu class to nav menu
        nav.classList.toggle('show-menu');
 
        // Add show-icon to show and hide the menu icon
        toggle.classList.toggle('show-icon');
    });
};

showMenu('nav-toggle', 'nav-menu');

/*=============== DROPDOWN CLICK TOGGLE ===============*/
// Sélectionner les items dropdown et leurs sous-menus
const dropdownItems = document.querySelectorAll('.dropdown__item');

dropdownItems.forEach(item => {
    const dropdownMenu = item.querySelector('.dropdown__menu');
    const dropdownArrow = item.querySelector('.dropdown__arrow');

    item.addEventListener('click', () => {
        // Vérifie si la largeur de l'écran est inférieure à 1024px
        if (window.innerWidth < 1024) {
            // Basculer l'affichage du menu déroulant
            if (dropdownMenu) {
                dropdownMenu.classList.toggle('open');
            }

            // Rotation de l'icône flèche
            if (dropdownArrow) {
                dropdownArrow.classList.toggle('rotated');
            }
        }
    });
});
