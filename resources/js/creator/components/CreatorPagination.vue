<script setup>
defineProps({ page: Number, lastPage: Number, total: Number, disabled: Boolean });
defineEmits(['page']);
</script>
<template><footer class="cs-pagination"><span>{{ total || 0 }} records · Page {{ page || 1 }} of {{ lastPage || 1 }}</span><div><button type="button" class="cs-button cs-button-quiet" :disabled="disabled || page <= 1" @click="$emit('page', page - 1)">Previous</button><button type="button" class="cs-button cs-button-quiet" :disabled="disabled || page >= lastPage" @click="$emit('page', page + 1)">Next</button></div></footer></template>
