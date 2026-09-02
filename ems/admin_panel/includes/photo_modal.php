<?php
/**
 * Styling and the click-to-enlarge viewer for photos captured by the app.
 *
 * Include once per page, at the end — it emits markup. The photoThumb() helper
 * that links to it lives in config.php, because the tables that call it are
 * rendered long before this include is reached.
 */
?>
<style>
.photo-thumb {
    width: 40px; height: 40px; object-fit: cover; border-radius: 50%;
    cursor: pointer; border: 2px solid #ddd; transition: all 0.3s;
}
.photo-thumb:hover { border-color: #1E3A5F; transform: scale(1.1); }
.photo-thumb-empty {
    width: 40px; height: 40px; border-radius: 50%;
    background: #f1f3f5; border: 2px dashed #dee2e6; color: #adb5bd;
    display: inline-flex; align-items: center; justify-content: center; font-size: 14px;
}
.photo-cell { display: flex; gap: 6px; align-items: center; }
</style>

<div class="modal fade" id="sharedPhotoModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sharedPhotoTitle">Photo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="sharedPhotoImg" src="" alt="" class="img-fluid rounded" style="max-height:70vh">
                <div id="sharedPhotoError" class="alert alert-warning mb-0" style="display:none">
                    This photo could not be loaded. The file may no longer be on the server.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function viewPhoto(src, title) {
    document.getElementById('sharedPhotoTitle').textContent = title || 'Photo';
    var img = document.getElementById('sharedPhotoImg');
    var err = document.getElementById('sharedPhotoError');
    err.style.display = 'none';
    img.style.display = 'block';
    img.onerror = function () { img.style.display = 'none'; err.style.display = 'block'; };
    img.src = src;
    new bootstrap.Modal(document.getElementById('sharedPhotoModal')).show();
}
</script>
