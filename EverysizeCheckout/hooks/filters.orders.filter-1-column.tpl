{* everysize Checkout — order list filter panel (filters/orders, filter-1-column).
   Rendered inside the filter panel; the checkbox feeds the "everysize_only"
   value that the orderlist hook applies. *}

<div class="flag-marker-row">
    <div class="tl_filters_title">everysize</div>
    <div class="wl-td w-tdc">
        <label class="radio_label">
            <input type="checkbox" name="everysize_only" value="1" {if $app->controller->view->filters->everysize_only|default:false}checked{/if} />
            {$smarty.const.EXT_EC_ORDERS_ONLY}
        </label>
    </div>
</div>
