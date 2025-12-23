<article>
    <hgroup>
        <h1><?php echo htmlspecialchars($errorHeading ?? 'Error'); ?></h1>
    </hgroup>
    <p><?php echo htmlspecialchars($errorMessage ?? 'An unexpected error occurred.'); ?></p>
</article>
