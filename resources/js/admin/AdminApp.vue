<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import { installGlassReflection } from './lib/glassReflection';
import { installSectionTilt } from './lib/sectionTilt';

let removeReflection;
let removeSectionTilt;
onMounted(() => {
    removeReflection = installGlassReflection();
    removeSectionTilt = installSectionTilt();
});
onBeforeUnmount(() => { removeReflection?.(); removeSectionTilt?.(); });
</script>
<template><RouterView/></template>
