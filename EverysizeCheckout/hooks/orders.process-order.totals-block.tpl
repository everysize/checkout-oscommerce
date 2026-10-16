{* everysize Checkout — the order detail box (orders/process-order, totals-block).
   Scope: $order (order model with order_id). Rendered only for everysize
   orders. Three sections like the accepted everysize design (section A,
   same structure as the other everysize plugins):
   1. "everysize-Bestellung" — info table,
   2. "Erstattung über everysize" — tiles + refunds table + form (only with
      a capture id),
   3. "Rückmeldungen an everysize" — the report table. *}

{$esBoxData = \common\extensions\EverysizeCheckout\backend\controllers\EverysizeCheckoutController::orderBoxData((string)$order->order_id)}
{if $esBoxData}
    {$esBoxReports = \common\extensions\EverysizeCheckout\backend\controllers\EverysizeCheckoutController::orderBoxReports((string)$order->order_id)}
    {$esRefundKey = \common\extensions\EverysizeCheckout\backend\controllers\EverysizeCheckoutController::ensureRefundKey((string)$order->order_id)}
    {$esPageToken = \common\extensions\EverysizeCheckout\backend\controllers\EverysizeCheckoutController::ensurePageToken()}

    <div id="everysize-order-box" style="border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin-top: 10px; max-width: 820px;">
        <h4 style="margin-top: 0;">{$smarty.const.EXT_EC_ORDER_BOX}</h4>

        {* --- 1. everysize-Bestellung --------------------------------------- *}
        <table class="table table-striped table-condensed" style="margin-bottom: 8px;">
            <tr><td style="width: 260px;">{$smarty.const.EXT_EC_ORDER_ES_ID}</td><td><b>{$esBoxData.EVERYSIZE_ORDER_ID}</b></td></tr>
            <tr><td>{$smarty.const.EXT_EC_ORDER_IMPORTED}</td><td>{\common\extensions\EverysizeCheckout\backend\controllers\EverysizeCheckoutController::formatUtc($esBoxData.CREATED)}</td></tr>
            <tr><td>{$smarty.const.EXT_EC_ORDER_NUMBER_REPORTED}</td><td>{if $esBoxData.NUMBER_REPORTED}{\common\extensions\EverysizeCheckout\backend\controllers\EverysizeCheckoutController::formatUtc($esBoxData.NUMBER_REPORTED)}{else}{$smarty.const.EXT_EC_ORDER_NUMBER_PENDING}{/if}</td></tr>
            {if $esBoxData.PAYPAL_CAPTURE_ID}
                <tr><td>{$smarty.const.EXT_EC_ORDER_CAPTURE}</td><td><code>{$esBoxData.PAYPAL_CAPTURE_ID}</code></td></tr>
            {/if}
            {if $esBoxData.ORIGINAL_EMAIL}
                <tr>
                    <td>{$smarty.const.EXT_EC_ORDER_EMAIL}</td>
                    <td>{$esBoxData.ORIGINAL_EMAIL}<div style="color: #999; font-size: 11px;">{$smarty.const.EXT_EC_ORDER_EMAIL_HINT}</div></td>
                </tr>
            {/if}
        </table>

        {* --- 2. Erstattung über everysize (nur mit Capture-ID) --------------- *}
        {if $esBoxData.PAYPAL_CAPTURE_ID}
            <h5 style="margin-top: 16px;">{$smarty.const.EXT_EC_REFUND_HEADING}</h5>
            {$esRefunds = \common\extensions\EverysizeCheckout\backend\controllers\EverysizeCheckoutController::refundData((string)$order->order_id)}
            <table class="table table-condensed" style="margin-bottom: 8px;">
                <tr>
                    <td style="padding: 6px 14px; border: 1px solid #ddd; border-radius: 4px; background: #f7f7f7;">
                        <b>{$esRefunds.format.paid}</b><br>
                        <span style="font-size: 11px;">{$smarty.const.EXT_EC_REFUND_TILE_PAID}</span>
                    </td>
                    <td style="padding: 6px 14px; border: 1px solid #ddd; border-radius: 4px; background: #f7f7f7;">
                        <b>{$esRefunds.format.refunded}</b><br>
                        <span style="font-size: 11px;">{$smarty.const.EXT_EC_REFUND_TILE_REFUNDED}</span>
                    </td>
                    <td style="padding: 6px 14px; border: 1px solid #ddd; border-radius: 4px; background: {if $esRefunds.open > 0.004}#fcf8e3{else}#f7f7f7{/if};">
                        <b>{$esRefunds.format.open}</b><br>
                        <span style="font-size: 11px;">{$smarty.const.EXT_EC_REFUND_TILE_OPEN}</span>
                    </td>
                </tr>
            </table>
            <table class="table table-striped table-condensed" style="margin-bottom: 8px;">
                <thead>
                    <tr>
                        <th>{$smarty.const.EXT_EC_REFUND_TABLE_DATE}</th>
                        <th>{$smarty.const.EXT_EC_REFUND_AMOUNT}</th>
                        <th>{$smarty.const.EXT_EC_REFUND_TABLE_STATUS}</th>
                        <th>{$smarty.const.EXT_EC_REFUND_TABLE_ID}</th>
                    </tr>
                </thead>
                <tbody>
                {if $esRefunds.rows|@count == 0}
                    <tr><td colspan="4" style="color: #999;">{$smarty.const.EXT_EC_REFUND_NONE}</td></tr>
                {else}
                    {foreach $esRefunds.rows as $refund}
                        <tr>
                            <td>{$refund.date}</td>
                            <td>{$refund.amount}</td>
                            <td><span class="{$refund.statusClass}">{$refund.status}</span></td>
                            <td><code>{$refund.id}</code></td>
                        </tr>
                    {/foreach}
                {/if}
                </tbody>
            </table>
            {$esCanReturn = \common\extensions\EverysizeCheckout\backend\controllers\EverysizeCheckoutController::canReportReturn((string)$order->order_id)}
            {if $esRefunds.open > 0.004}
                <div id="ec-refund-form" style="margin-bottom: 8px;">
                    <input type="hidden" id="ec-refund-key" value="{$esRefundKey}">
                    <label for="ec-refund-amount">{$smarty.const.EXT_EC_REFUND_AMOUNT}</label>
                    <input type="text" id="ec-refund-amount" class="form-control" style="display: inline-block; width: 140px;" value="{$esRefunds.openInput}" autocomplete="off">
                    &nbsp;
                    <button type="button" class="btn btn-primary ec-refund-send" data-refund="1">{$smarty.const.EXT_EC_REFUND_SEND}</button>
                    {if $esCanReturn}
                        <div class="checkbox ec-return-row" style="margin: 8px 0 0;">
                            <label><input type="checkbox" id="ec-report-return" value="1"> {$smarty.const.EXT_EC_RETURN_CHECKBOX}</label>
                        </div>
                    {/if}
                </div>
            {elseif $esCanReturn}
                <div id="ec-refund-form" class="ec-return-row" style="margin-bottom: 8px;">
                    <input type="hidden" id="ec-refund-key" value="{$esRefundKey}">
                    <button type="button" class="btn btn-default ec-refund-send" data-refund="0">{$smarty.const.EXT_EC_RETURN_SEND}</button>
                </div>
            {/if}
            <div id="ec-refund-message" style="margin-bottom: 8px;"></div>
            <div class="alert alert-info" style="padding: 8px 12px; max-width: 640px;">
                {$smarty.const.EXT_EC_REFUND_HINT|replace:'%s':$esRefunds.format.open}
                {if $esCanReturn && $esRefunds.open > 0.004}<br>{$smarty.const.EXT_EC_RETURN_HINT}{/if}
            </div>
        {/if}

        {* --- 3. Rückmeldungen an everysize ----------------------------------- *}
        <h5 style="margin-top: 16px;">{$smarty.const.EXT_EC_REPORTS_HEADING}</h5>
        {if $esBoxReports|@count == 0}
            {$smarty.const.EXT_EC_REPORTS_NONE}
        {else}
            <table class="table table-striped table-condensed" style="margin-bottom: 0;">
                <thead>
                    <tr>
                        <th>{$smarty.const.EXT_EC_PROTOCOL_TIME}</th>
                        <th>{$smarty.const.EXT_EC_PROTOCOL_MESSAGE}</th>
                        <th>{$smarty.const.EXT_EC_REPORTS_SUBJECT}</th>
                        <th>{$smarty.const.EXT_EC_REPORTS_REASON}</th>
                        <th>{$smarty.const.EXT_EC_REPORTS_RESULT}</th>
                    </tr>
                </thead>
                <tbody>
                {foreach $esBoxReports as $report}
                    <tr>
                        <td>{$report.date}</td>
                        <td>{$report.kind}</td>
                        <td>{if $report.subject == '–'}–{elseif $report.subject == $smarty.const.EXT_EC_REFUND_AUTO}{$report.subject}{else}<code>{$report.subject}</code>{/if}</td>
                        <td style="word-break: break-word;">{$report.reason}</td>
                        <td><span class="{$report.resultClass}">{$report.result}</span></td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        {/if}
    </div>

    <script>
        $(function () {
            $('.ec-refund-send').on('click', function () {
                var btn = $(this);
                var refund = String(btn.data('refund'));
                var reportReturn = refund === '0' || $('#ec-report-return').is(':checked');
                $('.ec-refund-send').prop('disabled', true);
                $.post('{$app->urlManager->createUrl('everysize-checkout/refund')}', {
                    orders_id: '{$order->order_id}',
                    amount: $('#ec-refund-amount').val() || '',
                    key: $('#ec-refund-key').val(),
                    refund: refund,
                    report_return: reportReturn ? '1' : '0',
                    ec_token: '{$esPageToken}'
                }, function (data) {
                    $('.ec-refund-send').prop('disabled', false);
                    if (data.ok) {
                        var parts = [], cls = 'alert-success';
                        if (data.returned) {
                            if (data.returned.skipped > 0) { parts.push('{$smarty.const.EXT_EC_RETURN_SKIPPED}'); cls = 'alert-warning'; }
                            else { parts.push('{$smarty.const.EXT_EC_RETURN_DONE}'); }
                            $('.ec-return-row').remove();
                        }
                        if (data.refunded) {
                            if (data.key) { $('#ec-refund-key').val(data.key); }
                            $('#ec-refund-amount').val('');
                            parts.push(data.already ? '{$smarty.const.EXT_EC_REFUND_ALREADY}' : '{$smarty.const.EXT_EC_REFUND_DONE}');
                            if (data.already && cls === 'alert-success') { cls = 'alert-info'; }
                        }
                        $('#ec-refund-message').html('<div class="alert ' + cls + '">' + parts.join(' ') + '</div>');
                    } else {
                        var m = data.message || '';
                        var msg = m.indexOf('invalid amount') === 0 ? '{$smarty.const.EXT_EC_REFUND_INVALID}'
                            : (m.indexOf('not shipped') === 0 ? '{$smarty.const.EXT_EC_RETURN_NOT_SHIPPED}'
                            : '{$smarty.const.EXT_EC_REFUND_FAILED}'.replace('%s', $('<div>').text(m).html()));
                        if (data.returned) {
                            msg = (data.returned.skipped > 0 ? '{$smarty.const.EXT_EC_RETURN_SKIPPED}' : '{$smarty.const.EXT_EC_RETURN_DONE}') + ' ' + msg;
                            $('.ec-return-row').remove();
                        }
                        $('#ec-refund-message').html('<div class="alert alert-danger">' + msg + '</div>');
                    }
                }, 'json').fail(function (xhr) {
                    $('.ec-refund-send').prop('disabled', false);
                    var msg = xhr && xhr.status === 400 ? '{$smarty.const.EXT_EC_REFUND_TOKEN}' : '{$smarty.const.EXT_EC_REFUND_FAILED}'.replace('%s', '');
                    $('#ec-refund-message').html('<div class="alert alert-danger">' + msg + '</div>');
                });
            });
        });
    </script>
{/if}
