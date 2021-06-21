jQuery(function ($) {
    console.log("Ура я в админке");
    $.fn.json_beautify= function() {
        var obj = JSON.parse( this.val() );
        var pretty = JSON.stringify(obj, undefined, 4);
        this.val(pretty);
    };

// Then use it like this on any textarea
    $('#woocommerce_stone-shipping_raw_data').json_beautify();

    $('#woocommerce_stone-shipping_raw_data').on('keyup', '[name="quantity"]', function(e) {
        console.log("chnge");
        $(this).json_beautify();
    });

});