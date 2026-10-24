Uploaded files land here at runtime:

  requirements/  - the documents and pictures students attach to a
                   reservation request (letter of request, concept
                   paper, layout, etc.)
  post-events/   - documentation photos attached to a post-event report

Both folders need to be writable by the web server (Apache/PHP).
On XAMPP for Windows this already works with no extra setup. On
Linux/macOS you may need:

  chmod -R 775 public/uploads

Do not delete the .htaccess in this folder - it is what stops the
files from being downloadable by guessing a URL.
