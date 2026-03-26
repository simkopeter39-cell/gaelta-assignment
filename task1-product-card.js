document.addEventListener("DOMContentLoaded", function () {
  const productCards = document.querySelectorAll(".product-card");
  const today = new Date();
  const THIRTY_DAYS_IN_MS = 30 * 24 * 60 * 60 * 1000;

  productCards.forEach(function (card) {
    const createdAt = card.dataset.createdAt;
    const price = Number(card.dataset.price);

    const badgeNew = card.querySelector(".badge-new");
    const freeShippingNote = card.querySelector(".free-shipping-note");

    if (createdAt) {
      const createdDate = new Date(createdAt);
      const productAge = today.getTime() - createdDate.getTime();

      if (productAge >= 0 && productAge <= THIRTY_DAYS_IN_MS) {
        badgeNew.hidden = false;
      }
    }

    if (!Number.isNaN(price) && price < 50) {
      freeShippingNote.hidden = false;
    }
  });
});