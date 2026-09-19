(function () {
  'use strict';
  let deferredPrompt = null;
  const modal = document.getElementById('installAppModal');
  const steps = document.getElementById('installAppSteps');
  const note = document.getElementById('installAppNote');
  const confirmButton = document.getElementById('installAppConfirm');
  const installButtons = [...document.querySelectorAll('[data-install-app]')];
  const standalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true || Boolean(window.cpmpNative);
  const appleMobile = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  function hideButtons() { installButtons.forEach(button => button.classList.add('hidden')); }
  function setSteps(items) { steps.replaceChildren(...items.map(item => { const li = document.createElement('li'); li.textContent = item; return li; })); }
  function close() { modal.classList.remove('open'); }
  function showInstructions() {
    if (standalone()) { hideButtons(); return; }
    confirmButton.hidden = true;
    if (appleMobile()) {
      setSteps(['Ouvrez cette page dans Safari.', 'Touchez le bouton Partager (le carré avec une flèche vers le haut).', 'Faites défiler puis choisissez « Sur l’écran d’accueil ».', 'Validez avec « Ajouter ».']);
      note.textContent = 'Sur iPhone et iPad, Safari installe CPMP – ASM comme une application web. Gardez Internet disponible pour la connexion et la synchronisation.';
    } else {
      setSteps(['Ouvrez le menu de votre navigateur.', 'Choisissez « Installer l’application » ou « Ajouter à l’écran d’accueil ».', 'Confirmez l’installation.']);
      note.textContent = 'Utilisez Chrome ou Edge si votre navigateur ne propose pas l’installation.';
    }
    if (deferredPrompt) {
      setSteps(['Touchez « Installer » ci-dessous.', 'Confirmez dans la fenêtre du navigateur.']);
      confirmButton.hidden = false;
      note.textContent = 'CPMP – ASM apparaîtra avec vos autres applications.';
    }
    modal.classList.add('open');
  }
  window.addEventListener('beforeinstallprompt', event => { event.preventDefault(); deferredPrompt = event; });
  window.addEventListener('appinstalled', () => { deferredPrompt = null; close(); hideButtons(); });
  confirmButton.addEventListener('click', async () => {
    if (!deferredPrompt) return showInstructions();
    const prompt = deferredPrompt; deferredPrompt = null;
    await prompt.prompt();
    const choice = await prompt.userChoice.catch(() => ({outcome:'dismissed'}));
    if (choice.outcome === 'accepted') close(); else showInstructions();
  });
  installButtons.forEach(button => button.addEventListener('click', showInstructions));
  modal.querySelector('.install-close').addEventListener('click', close);
  modal.querySelector('.install-cancel').addEventListener('click', close);
  modal.addEventListener('click', event => { if (event.target === modal) close(); });
  if (standalone()) hideButtons();
})();
