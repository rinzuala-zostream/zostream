export const releaseUploadMaxBytes = 512 * 1024 * 1024;

export const releaseUploadExtensions = {
    macos_update: ['dmg', 'pkg', 'zip'],
    windows_update: ['exe', 'msi', 'msix', 'msixbundle', 'appx', 'appxbundle', 'zip'],
};

export function releaseFileError(platform, file) {
    const extensions = releaseUploadExtensions[platform];
    if (!extensions) return 'Installer uploads are available for Mac and Windows only.';
    if (!file) return 'Choose an installer file first.';
    const extension = file.name.split('.').pop().toLowerCase();
    if (!extensions.includes(extension)) return `Choose a ${extensions.map(value => `.${value}`).join(', ')} file.`;
    if (file.size < 1024) return 'The installer file must be at least 1 KB.';
    if (file.size > releaseUploadMaxBytes) return 'The installer must be 512 MB or smaller.';
    return '';
}
