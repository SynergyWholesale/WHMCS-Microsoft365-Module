<script src="https://cdn.tailwindcss.com"></script>
<div class="service-main-container w-full mb-7">
    <div class="section-header text-blue-600 mb-3 text-2xl">
        <h2>Your Microsoft 365 Service Details</h2>
    </div>
    <div class="section-body w-full bg-white px-3 py-3 border-solid border-[0.5px] border-gray-300 rounded-[5px] flex flex-col gap-3 mb-3">
        <div class="section-row w-full flex gap-2 border-solid border-b-[0.5px] border-gray-200 py-1 items-end">
            <div class="section-row-title w-[30%] font-semibold">Status</div>
            <div class="section-row-value w-[70%] text-sm">{$service->domainstatus} {if $service->domainstatus eq 'Active'}<i class="fa fa-check text-green-500 ml-2"></i>{/if} </div>
        </div>
        <div class="section-row w-full flex gap-2 border-solid border-b-[0.5px] border-gray-200 py-1 items-end">
            <div class="section-row-title w-[30%] font-semibold">Service Domain</div>
            <div class="section-row-value w-[70%] text-sm">{$service->domain} <span class="italic font-semibold">({$product->name})</span></div>
        </div>
        {foreach from=$customFields key=fieldName item=fieldDetails}
            <div class="section-row w-full flex gap-2 border-solid border-b-[0.5px] border-gray-200 py-1 items-end">
                <div class="section-row-title w-[30%] font-semibold">{$fieldName}</div>
                <div class="section-row-value w-[70%] text-sm">
                    {if $fieldName == 'Customer Agreement'}
                        {if $fieldDetails['value'] eq 'on'}
                            YES <i class="fa fa-check text-green-500 ml-2"></i>
                        {else}
                            NO <i class="fa fa-xmark text-red-500 ml-2"></i>
                        {/if}
                    {else}
                        {$fieldDetails['value']}
                    {/if}
                </div>
            </div>
        {/foreach}
    </div>

    <div class="section-header text-blue-600 mb-3 text-2xl">
        <h2>Service's configuration options</h2>
    </div>
    <div class="section-body w-full bg-white px-3 py-3 border-solid border-[0.5px] border-gray-300 rounded-[5px] flex flex-col gap-3">
        {foreach from=$configOptions key=index item=optionDetails}
            <div class="section-row w-full flex gap-2 border-solid border-b-[0.5px] border-gray-200 py-1 items-end">
                <div class="section-row-title w-[50%] font-semibold">{$optionDetails['productName']}</div>
                <div class="section-row-value w-[50%] text-sm">
                    {if $optionDetails['quantity'] <= 0}
                        <span class="text-red-500 font-semibold mr-2">0</span> Seat
                    {else}
                        <span class="text-green-500 font-semibold mr-2">{$optionDetails['quantity']}</span> Seat(s)
                    {/if}
                </div>
            </div>
        {/foreach}
    </div>
</div>