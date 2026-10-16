{* everysize Checkout — sync protocol *}

<div class="order-wrap">
    <div class="row order-box-list order-sc-text">
        <div class="col-md-12">
            <div class="widget-content">

                <div class="btn-wr after btn-wr-top">
                    <div class="btn-group" role="group" aria-label="{$smarty.const.EXT_EC_PROTOCOL_HEADING}">
                        <a href="{$app->urlManager->createUrl(['everysize-checkout/protocol', 'filter' => 'all'])}" class="btn btn-default {if $app->controller->view->filter == 'all'}btn-primary active{/if}">{$smarty.const.EXT_EC_PROTOCOL_ALL}</a>
                        <a href="{$app->urlManager->createUrl(['everysize-checkout/protocol', 'filter' => 'ok'])}" class="btn btn-default {if $app->controller->view->filter == 'ok'}btn-primary active{/if}">{$smarty.const.EXT_EC_PROTOCOL_OK}</a>
                        <a href="{$app->urlManager->createUrl(['everysize-checkout/protocol', 'filter' => 'error'])}" class="btn btn-default {if $app->controller->view->filter == 'error'}btn-primary active{/if}">{$smarty.const.EXT_EC_PROTOCOL_ERROR}</a>
                    </div>
                </div>

                {if $app->controller->view->rows|@count == 0}
                    <div class="alert alert-info">{$smarty.const.EXT_EC_PROTOCOL_EMPTY}</div>
                {else}
                    <table class="table table-striped table-bordered">
                        <thead>
                        <tr>
                            <th style="width: 170px;">{$smarty.const.EXT_EC_PROTOCOL_TIME}</th>
                            <th style="width: 90px;">{$smarty.const.EXT_EC_PROTOCOL_STATE}</th>
                            <th style="width: 240px;">{$smarty.const.EXT_EC_PROTOCOL_TITLE}</th>
                            <th>{$smarty.const.EXT_EC_PROTOCOL_MESSAGE}</th>
                        </tr>
                        </thead>
                        <tbody>
                        {foreach $app->controller->view->rows as $row}
                            <tr>
                                <td>{\common\extensions\EverysizeCheckout\backend\controllers\EverysizeCheckoutController::formatUtc($row.created)}</td>
                                <td>{if $row.state}<span class="label label-success">{$smarty.const.EXT_EC_PROTOCOL_OK}</span>{else}<span class="label label-danger">{$smarty.const.EXT_EC_PROTOCOL_ERROR}</span>{/if}</td>
                                <td>{$row.title}</td>
                                <td>{$row.message|nl2br}</td>
                            </tr>
                        {/foreach}
                        </tbody>
                    </table>

                    {if $app->controller->view->pages > 1}
                        <ul class="pagination">
                            {for $p = 1 to $app->controller->view->pages}
                                <li {if $p == $app->controller->view->page}class="active"{/if}>
                                    <a href="{$app->urlManager->createUrl(['everysize-checkout/protocol', 'filter' => $app->controller->view->filter, 'page' => $p])}">{$p}</a>
                                </li>
                            {/for}
                        </ul>
                    {/if}
                {/if}

            </div>
        </div>
    </div>
</div>
