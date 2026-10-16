{* everysize Checkout — settings *}

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

                {if $app->controller->view->env.invalid}
                    <div class="alert alert-danger">
                        {$smarty.const.EXT_EC_ENV_INVALID}: <code>{$app->controller->view->env.url}</code>
                    </div>
                {/if}

                {\yii\helpers\Html::beginForm('', 'post', ['id' => 'ec-settings-form', 'enctype' => 'multipart/form-data'])}
                <input type="hidden" name="ec_token" value="{$app->controller->view->pageToken}">
                <table class="table table-striped table-bordered" style="max-width: 820px;">
                    <tr>
                        <td style="width: 260px;">{$smarty.const.EXT_EC_ENV_HEADING}</td>
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
                        <td>{$smarty.const.EXT_EC_FIELD_CLIENT_ID}</td>
                        <td>{\yii\helpers\Html::textInput('client_id', $app->controller->view->clientId, ['class' => 'form-control', 'maxlength' => 128, 'style' => 'max-width: 320px;'])}</td>
                    </tr>
                    <tr>
                        <td>{$smarty.const.EXT_EC_FIELD_CLIENT_SECRET}</td>
                        <td>
                            {\yii\helpers\Html::passwordInput('client_secret', '', ['class' => 'form-control', 'maxlength' => 128, 'style' => 'max-width: 320px;', 'autocomplete' => 'new-password'])}
                            {if $app->controller->view->secretStored}<div class="help-block">{$smarty.const.EXT_EC_SECRET_STORED}</div>{/if}
                        </td>
                    </tr>

                    <tr><td colspan="2"><b>{$smarty.const.EXT_EC_IMPORT_HEADING}</b></td></tr>
                    <tr>
                        <td>{$smarty.const.EXT_EC_IMPORT_STATUS}</td>
                        <td>
                            {\yii\helpers\Html::dropDownList('import_status', $app->controller->view->values.importStatus, $app->controller->view->statusOptions, ['class' => 'form-control', 'style' => 'max-width: 320px;'])}
                        </td>
                    </tr>
                    <tr>
                        <td>{$smarty.const.EXT_EC_FIELD_PLATFORM}</td>
                        <td>
                            {\yii\helpers\Html::dropDownList('platform_id', $app->controller->view->values.platformId, $app->controller->view->platformOptions, ['class' => 'form-control', 'style' => 'max-width: 320px;'])}
                        </td>
                    </tr>
                    <tr>
                        <td>{$smarty.const.EXT_EC_SEND_CONFIRMATION}</td>
                        <td>{\yii\helpers\Html::checkbox('send_order_confirmation', $app->controller->view->values.sendConfirmation)}</td>
                    </tr>

                    <tr><td colspan="2"><b>{$smarty.const.EXT_EC_EXPORT_HEADING}</b></td></tr>
                    <tr>
                        <td>{$smarty.const.EXT_EC_SHIPPING_STATUSES}</td>
                        <td>{\yii\helpers\Html::dropDownList('shipping_statuses', $app->controller->view->values.shippingStatuses, $app->controller->view->statusOptions, ['class' => 'form-control', 'multiple' => 'multiple', 'style' => 'max-width: 320px;'])}</td>
                    </tr>
                    <tr>
                        <td>{$smarty.const.EXT_EC_CANCELLATION_STATUSES}</td>
                        <td>{\yii\helpers\Html::dropDownList('cancellation_statuses', $app->controller->view->values.cancellationStatuses, $app->controller->view->statusOptions, ['class' => 'form-control', 'multiple' => 'multiple', 'style' => 'max-width: 320px;'])}</td>
                    </tr>
                    <tr>
                        <td>{$smarty.const.EXT_EC_RETURN_STATUSES}</td>
                        <td>{\yii\helpers\Html::dropDownList('return_statuses', $app->controller->view->values.returnStatuses, $app->controller->view->statusOptions, ['class' => 'form-control', 'multiple' => 'multiple', 'style' => 'max-width: 320px;'])}</td>
                    </tr>
                    <tr>
                        <td>{$smarty.const.EXT_EC_CARRIER}</td>
                        <td>
                            {\yii\helpers\Html::dropDownList('carrier', $app->controller->view->values.carrier, array_combine($app->controller->view->carriers, $app->controller->view->carriers), ['class' => 'form-control', 'style' => 'max-width: 320px;'])}
                        </td>
                    </tr>

                    <tr><td colspan="2"><b>{$smarty.const.EXT_EC_REFUND_HEADING}</b></td></tr>
                    <tr>
                        <td>{$smarty.const.EXT_EC_AUTO_REFUND}</td>
                        <td>{\yii\helpers\Html::checkbox('auto_refund_decline', $app->controller->view->values.autoRefund)}</td>
                    </tr>

                    <tr><td colspan="2"><b>{$smarty.const.EXT_EC_SYNC_HEADING}</b></td></tr>
                    <tr>
                        <td>{$smarty.const.EXT_EC_AUTO_SYNC}</td>
                        <td>{\yii\helpers\Html::checkbox('auto_sync', $app->controller->view->values.autoSync)}</td>
                    </tr>
                    <tr>
                        <td>{$smarty.const.EXT_EC_CRON_TOKEN}</td>
                        <td>
                            {\yii\helpers\Html::textInput('cron_token', $app->controller->view->values.cronToken, ['class' => 'form-control', 'maxlength' => 64, 'style' => 'max-width: 320px;'])}
                            <div class="help-block">
                                {$smarty.const.EXT_EC_CRON_HINT}
                                <code>{$app->controller->view->cronUrl}</code>
                                {if !$app->controller->view->cronTokenSet}
                                    — {$smarty.const.EXT_EC_CRON_NO_TOKEN}
                                {/if}
                            </div>
                        </td>
                    </tr>
                </table>

                <div class="btn-wr after" style="margin-top: 12px;">
                    {\yii\helpers\Html::submitButton($smarty.const.EXT_EC_SAVE, ['class' => 'btn btn-primary'])}
                    <button type="button" id="ec-test-connection" class="btn btn-default">{$smarty.const.EXT_EC_TEST_CONNECTION}</button>
                    <button type="button" id="ec-remove-credentials" class="btn btn-danger">{$smarty.const.EXT_EC_REMOVE_CREDENTIALS}</button>
                </div>
                {\yii\helpers\Html::endForm()}

                <div id="ec-message" style="margin-top: 10px; max-width: 820px;"></div>
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
                client_id: $('input[name=client_id]').val(),
                client_secret: $('input[name=client_secret]').val(),
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
        $('#ec-remove-credentials').on('click', function () {
            if (!confirm('{$smarty.const.EXT_EC_CONFIRM_REMOVE|escape:"javascript"}')) {
                return;
            }
            $.post('{$app->urlManager->createUrl('everysize-checkout/remove-credentials')}', {
                ec_token: '{$app->controller->view->pageToken}'
            }, function (data) {
                if (data.ok) {
                    ecShowMessage(true, '{$smarty.const.EXT_EC_CREDENTIALS_REMOVED}');
                    $('input[name=client_id]').val('');
                } else {
                    ecShowMessage(false, data.message || 'error');
                }
            }, 'json');
        });
    });
</script>
