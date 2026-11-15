/* Confirm dialogs for dangerous buttons: <form data-confirm="Are you sure?"> */
document.addEventListener('submit', function (e) {
  var m = e.target && e.target.getAttribute && e.target.getAttribute('data-confirm');
  if (m && !window.confirm(m)) { e.preventDefault(); }
});
