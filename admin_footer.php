        </main>
    </div>
</div>
<?php if ((isset($GLOBALS['conn']) && ppdb_admin_sync_is_due($GLOBALS['conn'])) || (function_exists('ppdb_geo_has_misses') && ppdb_geo_has_misses())) { ?><div data-admin-background hidden></div><?php } ?>
<?php $adminLiveState = isset($GLOBALS['conn']) ? ppdb_admin_live_state($GLOBALS['conn']) : null; ?>
<?php if ($adminLiveState) { ?><div data-admin-live="<?php echo h(json_encode($adminLiveState)); ?>" hidden></div><?php } ?>
<script src="js/admin.js?v=<?php echo (int) filemtime(__DIR__.'/js/admin.js'); ?>"></script>
<script src="js/admin-live.js?v=<?php echo (int) filemtime(__DIR__.'/js/admin-live.js'); ?>"></script>
</body>
</html>
