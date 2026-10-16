{* everysize Checkout — overview *}

<div class="order-wrap">
    <div class="row order-box-list order-sc-text">
        <div class="col-md-12">
            <div class="widget-content">

                {if Yii::$app->session->hasFlash('everysize_checkout_saved')}
                    <div class="alert alert-success alert-dismissible show" role="alert">
                        {Yii::$app->session->getFlash('everysize_checkout_saved')}
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    </div>
                {/if}

                <h4>{$smarty.const.EXT_EC_TITLE}</h4>
                <table class="table table-striped table-bordered" style="max-width: 820px;">
                    <tr>
                        <td style="width: 240px;"><b>{$smarty.const.EXT_EC_ENV_HEADING}</b></td>
                        <td>
                            {if $app->controller->view->env.invalid}
                                <span class="label label-danger">{$app->controller->view->env.label}</span>
                            {elseif $app->controller->view->env.label == $smarty.const.EXT_EC_ENV_PRODUCTION}
                                <span class="label label-success">{$app->controller->view->env.label}</span>
                            {else}
                                <span class="label label-info">{$app->controller->view->env.label}</span>
                            {/if}
                            &nbsp;<code>{$app->controller->view->env.url}</code>
                        </td>
                    </tr>
                    <tr>
                        <td><b>{$smarty.const.EXT_EC_FIELD_CLIENT_ID}</b></td>
                        <td>
                            {if $app->controller->view->configured}
                                <span class="label label-success">{$smarty.const.TEXT_OK}</span> <code>{$app->controller->view->clientId}</code>
                            {else}
                                <span class="label label-danger">{$smarty.const.EXT_EC_STATUS_NOT_CONFIGURED}</span>
                            {/if}
                        </td>
                    </tr>
                    <tr>
                        <td><b>osCommerce</b></td>
                        <td>{$app->controller->view->shopVersion}</td>
                    </tr>
                    <tr>
                        <td><b>{$smarty.const.EXT_EC_ORDER_ES_ID}</b></td>
                        <td>{$app->controller->view->counts.orders} {$smarty.const.EXT_EC_ORDERS_IMPORTED}</td>
                    </tr>
                </table>

                <div class="btn-wr after btn-wr-top" style="margin-top: 12px;">
                    <button type="button" id="ec-test-connection" class="btn btn-primary">{$smarty.const.EXT_EC_TEST_CONNECTION}</button>
                    <button type="button" id="ec-sync-now" class="btn btn-primary">{$smarty.const.EXT_EC_SYNC_NOW}</button>
                    <a href="{$app->urlManager->createUrl('everysize-checkout/settings')}" class="btn btn-default">{$smarty.const.EXT_EC_MENU_SETTINGS}</a>
                    <a href="{$app->urlManager->createUrl('everysize-checkout/protocol')}" class="btn btn-default">{$smarty.const.EXT_EC_MENU_PROTOCOL}</a>
                </div>
                <div id="ec-message" style="margin-top: 10px; max-width: 820px;"></div>

                {if $app->controller->view->lastRun|@count > 0}
                    <h4 style="margin-top: 24px;">{$smarty.const.EXT_EC_PROTOCOL_HEADING}</h4>
                    <table class="table table-striped table-bordered" style="max-width: 820px;">
                        {foreach $app->controller->view->lastRun as $row}
                            <tr>
                                <td style="width: 160px;">{\common\extensions\EverysizeCheckout\backend\controllers\EverysizeCheckoutController::formatUtc($row.created)}</td>
                                <td style="width: 70px;">{if $row.state}<span class="label label-success">{$smarty.const.EXT_EC_PROTOCOL_OK}</span>{else}<span class="label label-danger">{$smarty.const.EXT_EC_PROTOCOL_ERROR}</span>{/if}</td>
                                <td><b>{$row.title}</b> {$row.message}</td>
                            </tr>
                        {/foreach}
                    </table>
                {/if}

            </div>
        </div>
    </div>
</div>

<script>
    function ecShowMessage(ok, text) {
        var el = document.getElementById('ec-message');
        el.innerHTML = '<div class="alert ' + (ok ? 'alert-success' : 'alert-danger') + '">' + text + '</div>';
    }
    $(function () {
        $('#ec-test-connection').on('click', function () {
            var btn = $(this);
            btn.prop('disabled', true);
            $.post('{$app->urlManager->createUrl('everysize-checkout/test-connection')}', {
                ec_token: '{$app->controller->view->pageToken}'
            }, function (data) {
                btn.prop('disabled', false);
                if (data.ok) {
                    ecShowMessage(true, '{$smarty.const.EXT_EC_CONNECTION_OK}'.replace('%s', data.shop_id).replace('%s', data.url));
                } else {
                    ecShowMessage(false, data.message || 'error');
                }
            }, 'json').fail(function () { btn.prop('disabled', false); ecShowMessage(false, 'error'); });
        });
        $('#ec-sync-now').on('click', function () {
            var btn = $(this);
            btn.prop('disabled', true);
            $.post('{$app->urlManager->createUrl('everysize-checkout/sync-now')}', {
                ec_token: '{$app->controller->view->pageToken}'
            }, function (data) {
                btn.prop('disabled', false);
                var lines = (data.messages || []).concat(data.errors || []).join('<br>');
                ecShowMessage(data.ok, lines || '{$smarty.const.EXT_EC_SYNC_RUNNING}');
            }, 'json').fail(function () { btn.prop('disabled', false); ecShowMessage(false, 'error'); });
        });
    });
</script>
