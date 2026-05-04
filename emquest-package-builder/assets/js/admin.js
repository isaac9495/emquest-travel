(function ($) {
  function reindexHotels() {
    $('#emquest-hotels-wrap .emq-hotel-card').each(function (index) {
      $(this).find('input, textarea, select').each(function () {
        const name = $(this).attr('name');
        if (!name) return;
        $(this).attr('name', name.replace(/emquest\[hotels\]\[[^\]]+\]/, 'emquest[hotels][' + index + ']'));
      });
    });
  }

  $(document).on('click', '.emquest-remove-hotel', function () {
    $(this).closest('.emq-hotel-card').remove();
    reindexHotels();
  });

  $(document).on('click', '#emquest-add-hotel', function () {
    const wrap = $('#emquest-hotels-wrap');
    const idx = wrap.find('.emq-hotel-card').length;
    const tpl = $('#emquest-hotel-template').html().replace(/__INDEX__/g, idx);
    wrap.append(tpl);
    reindexHotels();
  });
})(jQuery);
