<div id="product-prices" class="panel product-tab">
    <input type="hidden" name="submitted_tabs[]" value="Prices"/>
    <h3>{l s='SizeID Size Chart' mod='sizeid'}</h3>

    <p>
        {l s='Select size chart which corresponds the given product. The size chart must be added into the Active Size charts list first. To do that, go to Active size charts section of [1]SizeID for Business interface[/1].' tags=['<a href="https://business.sizeid.com/">'] mod='sizeid'}
    </p>
    <fieldset style="border:none;">
        <select name="sizeid_size_chart_id">
            <option value="">{l s='No size chart selected - SizeID disabled for this product' mod='sizeid'}</option>
            {foreach from=$sizeid_size_chart_options key=id item=label}
                <option value="{$id}" {if $id == $sizeid_size_chart_id}selected{/if}>{$label}</option>
            {/foreach}
        </select>
    </fieldset>
    <div class="clear">&nbsp;</div>
    <div class="panel-footer">
        <a href="{$link->getAdminLink('AdminProducts')|escape:'html':'UTF-8'}{if isset($smarty.request.page) && $smarty.request.page > 1}&amp;submitFilterproduct={$smarty.request.page|intval}{/if}"
           class="btn btn-default"><i class="process-icon-cancel"></i> {l s='Cancel'}</a>
        <button type="submit" name="submitAddproduct" class="btn btn-default pull-right" disabled="disabled"><i
                    class="process-icon-loading"></i> {l s='Save'}</button>
        <button type="submit" name="submitAddproductAndStay" class="btn btn-default pull-right" disabled="disabled"><i
                    class="process-icon-loading"></i> {l s='Save and stay'}</button>
    </div>
</div>