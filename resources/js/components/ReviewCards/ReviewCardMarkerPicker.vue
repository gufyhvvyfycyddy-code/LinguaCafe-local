<template>
    <div class="review-card-marker-picker">
        <v-menu offset-y :disabled="!canMutate || saving">
            <template v-slot:activator="{ on, attrs }">
                <v-btn
                    v-bind="attrs"
                    v-on="on"
                    :small="dense"
                    :icon="dense"
                    :disabled="!canMutate || disabled"
                    :loading="saving"
                    :aria-label="buttonLabel"
                    class="review-card-marker-picker__button"
                    text
                >
                    <v-icon :color="currentOption.color" :small="dense">
                        {{ currentOption.icon }}
                    </v-icon>
                    <span v-if="!dense" class="ml-1">{{ currentOption.label }}</span>
                </v-btn>
            </template>

            <v-list dense class="review-card-marker-picker__menu">
                <v-list-item
                    v-for="option in markerOptions"
                    :key="option.value"
                    :disabled="saving"
                    @click="save(option.value)"
                >
                    <v-list-item-icon class="mr-3">
                        <v-icon :color="option.color">{{ option.icon }}</v-icon>
                    </v-list-item-icon>
                    <v-list-item-content>
                        <v-list-item-title>{{ option.label }}</v-list-item-title>
                    </v-list-item-content>
                </v-list-item>
            </v-list>
        </v-menu>

        <v-alert
            v-if="errorMessage"
            dense
            text
            type="error"
            class="review-card-marker-picker__error mt-1 mb-0"
        >
            {{ errorMessage }}
        </v-alert>
    </div>
</template>

<script>
import axios from 'axios';
import {
    REVIEW_CARD_MARKERS,
    markerOption,
} from '../../services/ReviewCardMarkerPresentation';

export default {
    name: 'ReviewCardMarkerPicker',
    props: {
        cardId: {
            type: [Number, String],
            default: null,
        },
        marker: {
            type: [Number, String],
            default: 0,
        },
        ids: {
            type: Array,
            default: () => [],
        },
        disabled: {
            type: Boolean,
            default: false,
        },
        dense: {
            type: Boolean,
            default: false,
        },
    },
    data() {
        return {
            saving: false,
            errorMessage: '',
        };
    },
    computed: {
        markerOptions() {
            return REVIEW_CARD_MARKERS;
        },
        currentOption() {
            return markerOption(this.marker);
        },
        normalizedIds() {
            return [...new Set(this.ids.map(Number).filter(id => Number.isInteger(id) && id > 0))];
        },
        isBulk() {
            return this.normalizedIds.length > 0;
        },
        normalizedCardId() {
            const id = Number(this.cardId);
            return Number.isInteger(id) && id > 0 ? id : null;
        },
        canMutate() {
            return !this.disabled && (this.isBulk || this.normalizedCardId !== null);
        },
        buttonLabel() {
            return this.isBulk
                ? `设置 ${this.normalizedIds.length} 张卡片的标记`
                : `卡片标记：${this.currentOption.label}`;
        },
    },
    methods: {
        save(marker) {
            const value = Number(marker);
            if (!this.canMutate || this.saving || !Number.isInteger(value) || value < 0 || value > 7) {
                return;
            }

            const submittedIds = this.normalizedIds.slice();
            this.saving = true;
            this.$emit('saving-change', true);
            this.errorMessage = '';

            const request = this.isBulk
                ? axios.post('/review-cards/manage/bulk-marker', { ids: submittedIds, marker: value })
                : axios.patch(`/review-cards/manage/${this.normalizedCardId}/marker`, { marker: value });

            request
                .then((response) => {
                    this.$emit('updated', response.data, submittedIds);
                    this.$emit('notify', '卡片标记已更新。', 'success');
                })
                .catch((error) => {
                    this.errorMessage = error.response?.data?.message || '更新卡片标记失败。';
                    this.$emit('notify', this.errorMessage, 'error');
                })
                .finally(() => {
                    this.saving = false;
                    this.$emit('saving-change', false);
                });
        },
    },
};
</script>
