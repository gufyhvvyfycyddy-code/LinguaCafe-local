<template>
    <v-menu offset-y>
        <template #activator="{ on, attrs }">
            <v-btn x-small text v-bind="attrs" v-on="on" :disabled="disabled" :loading="loading">
                <v-icon small left :color="current.color">mdi-flag</v-icon>{{ current.label }}
            </v-btn>
        </template>
        <v-list dense>
            <v-list-item v-for="choice in markerChoices" :key="choice.value" @click="$emit('change', choice.value)">
                <v-list-item-icon><v-icon small :color="choice.color">mdi-flag</v-icon></v-list-item-icon>
                <v-list-item-title>{{ choice.label }}</v-list-item-title>
            </v-list-item>
        </v-list>
    </v-menu>
</template>

<script>
import { markerChoice, markerChoices } from '../../services/ReviewCardMarkerPresentation.js';

export default {
    name: 'ReviewCardMarkerPicker',
    props: {
        value: { type: Number, default: 0 },
        disabled: { type: Boolean, default: false },
        loading: { type: Boolean, default: false },
    },
    computed: {
        markerChoices() { return markerChoices; },
        current() { return markerChoice(this.value); },
    },
};
</script>
