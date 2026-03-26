document.addEventListener('DOMContentLoaded', function() {
  const addToCartBtns = document.querySelectorAll('.btn-add-to-cart');

  addToCartBtns.forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.preventDefault();

      const productId = btn.getAttribute('data-product');
      const quantity = document.querySelector('#qty-' + productId).value;

      fetch('/api/cart/add', {
        method: 'POST',
        body: JSON.stringify({
          product_id: productId,
          qty: quantity
        })
      })
      .then(response => response.json())
      .then(data => {
        console.log('Pridané do košíka', data);
      });
    });
  });
});
