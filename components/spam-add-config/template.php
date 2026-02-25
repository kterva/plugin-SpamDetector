<?php

/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-tag-list
');

?>
<div id="spam-add-config">

    <div class="spam-add-config__content" style="background:#fff; padding:20px; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
        <div class="spam-add-config__notification">
            <div class="spam-add-config__add">
                <span class="spam-add-config__title semibold"> <?= i::__('Notificações', 'spamDetector')?></span>
                <div class="field">
                    <input type="text" placeholder="<?= i::esc_attr__('Digite uma nova palavra chave de notificação', 'spamDetector') ?>" @keydown="change($event, 'notificationTags')"  @blur="clear($event)">
                </div>    
            </div>
            <mc-tag-list class="spam-add-config__tags scrollbar" classes="spam-add-config__tag spam-add-config__tag--notification" :tags="notificationTags" @remove="saveTags()" editable></mc-tag-list>
        </div>

        <div class="spam-add-config__vertical-divisor" style="margin:20px 0;"></div>
    
        <div class="spam-add-config__block">
            <div class="spam-add-config__add">
                <span class="spam-add-config__title semibold"> <?= i::__('Bloqueio', 'spamDetector')?></span>
                <div class="field">
                    <input type="text" placeholder="<?= i::esc_attr__('Digite uma nova palavra chave de bloqueio', 'spamDetector') ?>" @keydown="change($event, 'blockedTags')" @blur="clear($event)">
                </div>
            </div>
            <mc-tag-list class="spam-add-config__tags scrollbar" classes="spam-add-config__tag spam-add-config__tag--block" :tags="blockedTags"  @remove="saveTags()" editable></mc-tag-list>
        </div>
    </div>

</div>