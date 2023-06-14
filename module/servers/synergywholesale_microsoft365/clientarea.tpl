<script src="https://cdn.tailwindcss.com"></script>
<div class="service-main-container w-full mb-7">
    <div class="section-header w-full text-red-600 mb-2 font-semibold flex gap-3 items-end">
        <h2 class=" text-2xl">Billing Information</h2>
        <a class="px-3 py-[6px] no-underline hover:no-underline text-white bg-red-600 rounded-[5px] text-sm hover:bg-red-700 cursor-pointer" href="clientarea.php?action=cancel&id={$service->id}">Request Cancellation</a>
    </div>
    <div class="section-body w-full bg-white px-3 py-3 border-solid border-[0.5px] border-gray-300 rounded-[5px] flex flex-col gap-3">
        {foreach from=$billing key=fieldName item=fieldDetails}
            <div class="section-row w-full flex gap-2 border-solid border-b-[0.5px] border-gray-200 py-1 items-end">
                <div class="section-row-title w-[30%] font-semibold">{$fieldName}</div>
                <div class="section-row-value w-[70%] text-sm">
                    {if $fieldName == 'Next Due Date'}
                        <span class="text-red-500 font-semibold">
                            {$fieldDetails['value']}

                            {if $serviceIsOverdue == true}
                                <span>(Overdue)</span>
                            {/if}
                        </span>
                    {else}
                        {$fieldDetails['value']}
                    {/if}
                </div>
            </div>
        {/foreach}
    </div>

    <div class="section-header text-blue-600 mb-2 text-2xl mt-4 font-semibold">
        <h2>Your Microsoft 365 Service Details</h2>
    </div>
    <div class="section-body w-full bg-white px-3 py-3 border-solid border-[0.5px] border-gray-300 rounded-[5px] flex flex-col gap-3">
        <div class="section-row w-full flex gap-2 border-solid border-b-[0.5px] border-gray-200 py-1 items-end">
            <div class="section-row-title w-[30%] font-semibold">Status</div>
            <div class="section-row-value w-[70%] text-sm">{$service->domainstatus} {if $service->domainstatus eq 'Active'}<i class="fa fa-check text-green-500 ml-2"></i>{/if} </div>
        </div>
        <div class="section-row w-full flex gap-2 border-solid border-b-[0.5px] border-gray-200 py-1 items-end">
            <div class="section-row-title w-[30%] font-semibold">Service Domain</div>
            <div class="section-row-value w-[70%] text-sm">{$service->domain} <span class="italic font-semibold">({$product->name})</span></div>
        </div>
        <div class="section-row w-full flex gap-2 border-solid border-b-[0.5px] border-gray-200 py-1 items-end">
            <div class="section-row-title w-[30%] font-semibold">Domain Username</div>
            <div class="section-row-value w-[70%] text-sm">
                {$service->username}
                <a info="Login via Microsoft 365 Portal" href="https://portal.office.com/" target="_blank" class="text-blue-500 font-semibold ml-2 hover:text-blue-700">Login MS 365 Portal</a>
            </div>
        </div>
        <div class="section-row w-full flex gap-2 border-solid border-b-[0.5px] border-gray-200 py-1 items-end">
            <div class="section-row-title w-[30%] font-semibold">Password</div>
            <div class="section-row-value w-[70%] text-sm">
                {$domainPassword}
            </div>
        </div>
        {foreach from=$customFields key=fieldName item=fieldDetails}
            <div class="section-row w-full flex gap-2 border-solid border-b-[0.5px] border-gray-200 py-1 items-end">
                <div class="section-row-title w-[30%] font-semibold">{$fieldName}</div>
                <div class="section-row-value w-[70%] text-sm">
                    {if $fieldName == 'Customer Agreement'}
                        {if $fieldDetails['value'] eq 'on'}
                            YES <i class="fa fa-check text-green-500 ml-2"></i>
                        {else}
                            NO
                        {/if}
                        <a info="View Microsoft 365 Customer Agreement" href="https://www.microsoft.com/licensing/docs/customeragreement" target="_blank" class="text-blue-500 font-semibold ml-2 hover:text-blue-700">View MS 365 Agreement</a>
                    {else}
                        {$fieldDetails['value']}
                    {/if}
                </div>
            </div>
        {/foreach}
    </div>

    <div class="section-header w-full text-blue-600 mb-2 mt-4 font-semibold flex gap-3 items-end">
        <h2 class=" text-2xl">Service's configuration options</h2>
        <a class="px-3 py-[6px] no-underline hover:no-underline text-white bg-green-600 rounded-[5px] text-sm hover:bg-green-700 cursor-pointer" target="_blank" href="upgrade.php?type=package&id={$service->id}">Change Package</a>
        <a class="px-3 py-[6px] no-underline hover:no-underline text-white bg-yellow-600 rounded-[5px] text-sm hover:bg-yellow-700 cursor-pointer" target="_blank" href="upgrade.php?type=configoptions&id={$service->id}">Change Subscriptions</a>
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

    <div class="section-header text-blue-600 mb-2 text-2xl mt-4 font-semibold">
        <h2>Server Details</h2>
    </div>
    <div class="section-body w-full bg-white px-3 py-3 border-solid border-[0.5px] border-gray-300 rounded-[5px] flex flex-col gap-3">
        <div class="section-row w-full flex gap-2 border-solid border-b-[0.5px] border-gray-200 py-1 items-end">
            <div class="section-row-title w-[30%] font-semibold">Server Host Name</div>
            <div class="section-row-value w-[70%] text-sm">{$server['serverName']}</div>
        </div>
        <div class="section-row w-full flex gap-2 border-solid border-b-[0.5px] border-gray-200 py-1 items-end">
            <div class="section-row-title w-[30%] font-semibold">IP Address</div>
            <div class="section-row-value w-[70%] text-sm">{$server['ipAddress']}</div>
        </div>
    </div>
</div>