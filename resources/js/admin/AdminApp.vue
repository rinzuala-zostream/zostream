<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import { installGlassReflection } from './lib/glassReflection';

let removeReflection;
onMounted(() => {
    removeReflection = installGlassReflection();
});
onBeforeUnmount(() => { removeReflection?.(); });
</script>
<template><RouterView/></template>
