<?php
/*
Plugin Name: Labi Document Handler
Description: Upload documents via Contact Form 7 and generate preview images from PDF and Word files.
Version: 1.8
Author: You
*/
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

add_filter('woocommerce_get_price_html', 'labi_append_cena_ar_pvn', 20, 2);
add_filter('woocommerce_cart_item_price', 'labi_append_cena_ar_pvn', 20, 2);
add_filter('woocommerce_cart_item_subtotal', 'labi_append_cena_ar_pvn', 20, 2);
add_filter('woocommerce_cart_totals_order_total_html', 'labi_append_cena_ar_pvn_total', 20, 1);
add_filter('woocommerce_checkout_cart_item_quantity', 'labi_append_cena_ar_pvn_checkout_qty', 20, 3);

function labi_append_cena_ar_pvn($price, $product = null) {
    if (!is_admin()) {
        $price .= ' <small style="font-size:0.85em; color:#777;">Cena ar PVN</small>';
    }
    return $price;
}

function labi_append_cena_ar_pvn_total($total_html) {
    if (!is_admin()) {
        $total_html .= ' <br><small style="font-size:0.85em; color:#777;">Cena ar PVN</small>';
    }
    return $total_html;
}

function labi_append_cena_ar_pvn_checkout_qty($quantity_html, $cart_item, $cart_item_key) {
    $product = $cart_item['data'];
    $price = wc_price($product->get_price());
    return $quantity_html . '<br><small style="font-size:0.85em; color:#777;">Cena ar PVN</small>';
}

add_action('init', function () {
    if (!is_admin()) return;

    $upload_dir = wp_upload_dir();
    $doc_folder = $upload_dir['basedir'] . '/documents';
    if (!is_dir($doc_folder)) return;

    foreach (glob($doc_folder . '/*.{pdf,doc,docx}', GLOB_BRACE) as $file) {
        // Skip if modified very recently (likely just uploaded)
        if (time() - filemtime($file) < 30) continue;

        // Build file URL
        $url = $upload_dir['baseurl'] . '/documents/' . basename($file);

        // Check if any post already uses this file URL (product or document)
        $existing = get_posts([
            'post_type'   => ['document', 'product'],
            'post_status' => 'any',
            'meta_key'    => 'document_file_url',
            'meta_value'  => $url,
            'numberposts' => 1,
        ]);

        if (!empty($existing)) {
            continue; // Already imported
        }

        // Create post
        $title = pathinfo($file, PATHINFO_FILENAME);
        $post_id = wp_insert_post([
            'post_type'   => 'document',
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_author' => get_current_user_id(),
        ]);

        if (is_wp_error($post_id)) {
            error_log("❌ Failed to create post for $file: " . $post_id->get_error_message());
            continue;
        }

        // Store file URL in post meta
        update_post_meta($post_id, 'document_file_url', $url);

        // Generate preview images
        try {
            labi_convert_pdf_to_images($post_id, $file);
        } catch (Throwable $e) {
            error_log("❌ Error generating images for $file: " . $e->getMessage());
        }
    }
});

add_action('woocommerce_before_shop_loop_item', function () {
    echo '<div class="labi-product-image-wrapper" style="position:relative;">';
}, 5);

add_action('woocommerce_before_shop_loop_item_title', function () {
    echo do_shortcode('[yith_wcwl_add_to_wishlist]');
}, 9);

add_action('woocommerce_before_shop_loop_item_title', function () {
    echo '</div>'; // Close .labi-product-image-wrapper
}, 11);

add_filter('upload_mimes', function ($mimes) {
    $mimes['pdf']  = 'application/pdf';
    $mimes['doc']  = 'application/msword';
    $mimes['docx'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    $mimes['xls']  = 'application/vnd.ms-excel';
    $mimes['xlsx'] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    $mimes['pptx'] = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    return $mimes;
});

add_action('init', function () {
    if (isset($_GET['force_convert']) && current_user_can('administrator')) {
        $pdf_path = wp_upload_dir()['basedir'] . '/documents/test.pdf';
        $post_id = 999;
        labi_convert_pdf_to_images($post_id, $pdf_path);
        exit("🔨 Manual convert triggered.");
    }
});

function labi_convert_pdf_to_images($post_id, $pdf_path) {
    if (!file_exists($pdf_path)) {
        error_log("❌ PDF file not found: $pdf_path");
        return;
    }

    $blur_mode = get_post_meta($post_id, '_blur_mode', true) ?: 'other';

    $allowed_pages = match ($blur_mode) {
        'instrukcijas' => ['full', 'full', 'partial_blur'],
        'riski'        => ['full', 'partial_blur'],
        'other'        => ['partial_blur'],
        default        => ['partial_blur'],
    };

    $upload_dir = wp_upload_dir();
    $allowed_pages = array_values($allowed_pages);
    foreach ($allowed_pages as $i => $type) {
        try {
            $imagick = new Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImage("{$pdf_path}[{$i}]");
            $imagick->setImageFormat('jpg');
            $imagick->resizeImage(800, 0, Imagick::FILTER_LANCZOS, 1);

            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();

            $final = new Imagick();
            $final->newImage($width, $height, new ImagickPixel('white'));

            if ($type === 'full') {
                $final->compositeImage($imagick, Imagick::COMPOSITE_DEFAULT, 0, 0);

            } elseif ($type === 'partial_blur') {
                $visibleHeight = (int)($height * 0.3);
                $blurHeight = $height - $visibleHeight;

                $top = clone $imagick;
                $top->cropImage($width, $visibleHeight, 0, 0);

                $bottom = clone $imagick;
                $bottom->cropImage($width, $blurHeight, 0, $visibleHeight);
                $bottom->blurImage(40, 20);

                $final->compositeImage($top, Imagick::COMPOSITE_DEFAULT, 0, 0);
                $final->compositeImage($bottom, Imagick::COMPOSITE_DEFAULT, 0, $visibleHeight);

                $top->clear(); $top->destroy();
                $bottom->clear(); $bottom->destroy();
            }

            $final->setImageFormat('jpg');
            $filename = $upload_dir['basedir'] . "/doc_{$post_id}_page_{$i}.jpg";
            $final->writeImage($filename);

            $final->clear(); $final->destroy();
            $imagick->clear(); $imagick->destroy();
        } catch (Exception $e) {
            error_log("❌ Failed to generate image for page $i: " . $e->getMessage());
        }
    }
}

add_action('wpcf7_mail_sent', 'labi_handle_cf7_upload');
function labi_handle_cf7_upload($contact_form) {
    try {
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) return;

        $data = $submission->get_posted_data();
        $files = $submission->uploaded_files();
        $file_path = $files['doc-file'] ?? null;
        $file_path = is_array($file_path) ? $file_path[0] : $file_path;
        $title = sanitize_text_field($data['doc-title'] ?? 'Untitled Document');

        if (!$file_path || !file_exists($file_path)) {
            error_log("❌ [CF7] Uploaded file not found.");
            return;
        }

        $upload_dir = wp_upload_dir();
        $filename = basename($file_path);
        $target_dir = $upload_dir['basedir'] . '/documents/';
        $new_path = $target_dir . $filename;

        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
        }

        if (!copy($file_path, $new_path)) {
            error_log("❌ [CF7] Failed to copy uploaded file.");
            return;
        }

        $ext = strtolower(pathinfo($new_path, PATHINFO_EXTENSION));
        $final_pdf_path = $new_path;

        if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'pptx'])) {
            $soffice = '/home/u510781621/libreoffice/libreoffice_all/opt/libreoffice25.2/program/soffice';
            $input_path = realpath($new_path);
            $output_dir = realpath($target_dir);
            $converted_pdf = $output_dir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.pdf';

            $cmd = "$soffice --headless --convert-to pdf --outdir \"$output_dir\" \"$input_path\"";
            exec($cmd, $output, $result);

            sleep(2);
            if ($result !== 0 || !file_exists($converted_pdf)) {
                error_log("❌ [LibreOffice] Conversion failed.");
                return;
            }

            $final_pdf_path = $converted_pdf;
        }

        $post_id = wp_insert_post([
            'post_type'   => 'document',
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_author' => get_current_user_id(),
        ]);

        if (is_wp_error($post_id)) {
            error_log("❌ [Post] Failed: " . $post_id->get_error_message());
            return;
        }

        update_post_meta($post_id, 'document_file_url', $upload_dir['baseurl'] . '/documents/' . basename($final_pdf_path));
        labi_convert_pdf_to_images($post_id, $final_pdf_path);

    } catch (Throwable $e) {
        error_log("❌ [Exception] " . $e->getMessage());
    }
}
add_action('after_setup_theme', function () {
    // Remove default star rating from summary (above price)
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);

    // Move it into Reviews tab
    add_filter('woocommerce_product_tabs', function($tabs) {
        if (isset($tabs['reviews'])) {
            $original_callback = $tabs['reviews']['callback'] ?? 'comments_template';
            $tabs['reviews']['callback'] = function() use ($original_callback) {
                global $product;

                echo '<div class="product-rating-in-reviews-tab" style="margin-bottom: 20px;">';
                wc_get_template('single-product/rating.php');
                echo '</div>';

                if (is_callable($original_callback)) {
                    call_user_func($original_callback);
                } else {
                    comments_template();
                }
            };
        }
        return $tabs;
    });
});


add_shortcode('doc_preview', function () {
    $post_id = get_the_ID();
    $upload_dir = wp_upload_dir();
    $html = '<div class="doc-preview">';
    $found = false;

   $hasImage = false;
    for ($i = 0; $i < 5; $i++) {
        $img_path = $upload_dir['basedir'] . "/doc_{$post_id}_page_{$i}.jpg";
        $img_url  = $upload_dir['baseurl'] . "/doc_{$post_id}_page_{$i}.jpg";
        if (file_exists($img_path)) {
            $html .= "<img src='$img_url' style='max-width:100%; margin-bottom:10px;' />";
            $hasImage = true;
        }
    }

    if (!$hasImage) {
        $file_url = get_post_meta($post_id, 'document_file_url', true);
        $html .= "<p>📄 <a href='$file_url' target='_blank'>Download document</a></p>";
    }

    return $html . '</div>';
});

add_filter('the_content', function ($content) {
    global $post;
    if (!$post || $post->post_type !== 'product') {
        return $content;
    }

    $product = wc_get_product($post->ID);
    $upload_dir = wp_upload_dir();

    // Check if product is downloadable
    $is_downloadable = $product && $product->is_downloadable();

    // Check if any preview images exist
    $has_preview_image = false;
    for ($i = 0; $i < 5; $i++) {
        $img_path = $upload_dir['basedir'] . "/doc_{$post->ID}_page_{$i}.jpg";
        if (file_exists($img_path)) {
            $has_preview_image = true;
            break;
        }
    }

    if ($is_downloadable || $has_preview_image) {
        return do_shortcode('[doc_preview]') . $content;
    }

    // Else: product is not downloadable and has no preview — show just content
    return $content;
});


add_filter('wpcf7_skip_mail', '__return_true');

add_action('init', function () {
    if (!is_admin()) return;

    $upload_dir = wp_upload_dir();
    $doc_folder = $upload_dir['basedir'] . '/documents';
    if (!is_dir($doc_folder)) return;

    foreach (glob($doc_folder . '/*.{pdf,doc,docx}', GLOB_BRACE) as $file) {
        $title = pathinfo($file, PATHINFO_FILENAME);
        $url = $upload_dir['baseurl'] . '/documents/' . basename($file);

        // Only continue if no document post already exists with this file URL
        $existing = get_posts([
            'post_type'   => ['document', 'product'], // check both
            'meta_key'    => 'document_file_url',
            'meta_value'  => $url,
            'numberposts' => 1,
        ]);

        if (!empty($existing)) continue;

        $post_id = wp_insert_post([
            'post_type'   => 'document',
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_author' => get_current_user_id(),
        ]);

        if (!is_wp_error($post_id)) {
            update_post_meta($post_id, 'document_file_url', $url);
            labi_convert_pdf_to_images($post_id, $file);
        }
    }
});

add_action('wp_after_insert_post', function ($post_id, $post, $update) {
    if ($post->post_type !== 'product') return;

    $product = wc_get_product($post_id);
    if (!$product || !$product->is_downloadable()) return;

    $downloads = $product->get_downloads();
    if (empty($downloads)) {
        error_log("❌ [after_insert_post] No downloads for product ID $post_id");
        return;
    }

    $upload_dir = wp_upload_dir();
    $baseurl = $upload_dir['baseurl'];
    $basedir = $upload_dir['basedir'];

    foreach ($downloads as $download_id => $download) {
        $url = $download['file'];
        $filename = basename($url);
        $target_dir = $basedir . '/documents/';
        $local_path = $target_dir . $filename;

        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
            error_log("📁 Created /documents/ folder");
        }

        if (strpos($url, $baseurl) === 0) {
            $source_path = $basedir . str_replace($baseurl, '', $url);

            if (file_exists($source_path)) {
                if (copy($source_path, $local_path)) {
                    error_log("✅ Copied file to: $local_path");
                    labi_process_uploaded_document($post_id, $local_path, $filename);
                } else {
                    error_log("❌ Failed to copy file from $source_path to $local_path");
                }
            } else {
                error_log("❌ File does not exist at source path: $source_path");
            }

        } else {
            error_log("❌ Unsupported download URL: $url");
        }
    }
}, 10, 3);

add_action('woocommerce_after_single_product_summary', function () {
    echo '</div>'; // Close .labi-product-summary
    echo '</div>'; // Close .labi-product-wrapper
}, 0);

add_filter('woocommerce_placeholder_img_src', 'labi_remove_placeholder_image');
function labi_remove_placeholder_image($src) {
    if (is_product()) {
        return ''; // prevent default placeholder
    }
    return $src;
}

add_action('woocommerce_before_single_product_summary', function () {
    global $post;
    if (!$post || get_post_type($post) !== 'product') return;

    $product = wc_get_product($post->ID);
    $post_id = $post->ID;
    $upload_dir = wp_upload_dir();

    echo '<div class="labi-product-wrapper" style="display:flex; flex-wrap:wrap; gap:40px; margin-bottom:40px;">';

    // LEFT SIDE
    echo '<div class="labi-doc-preview-gallery" style="flex:1; min-width:300px; display:flex; flex-direction:row; flex-wrap:wrap; gap:10px;">';

    $hasPreviews = false;

    // ✅ Show document previews ONLY if downloadable
    if ($product && $product->is_downloadable()) {
        for ($i = 0; $i < 10; $i++) {
            $img_path = $upload_dir['basedir'] . "/doc_{$post_id}_page_{$i}.jpg";
            $img_url  = $upload_dir['baseurl'] . "/doc_{$post_id}_page_{$i}.jpg";
            if (file_exists($img_path)) {
                echo "<a href='$img_url' class='doc-lightbox' data-gallery='product-{$post_id}' data-glightbox='title: Preview page ".($i+1)."'>
                        <img src='$img_url' style='height:200px; object-fit:contain; border:1px solid #ccc; padding:4px; background:#fff;' />
                      </a>";
                $hasPreviews = true;
            }
        }
    }

    // ✅ If no previews found, fallback to featured image
    if (!$hasPreviews) {
        woocommerce_show_product_images(); // ✅ SAFE function
    }

    echo '</div>'; // End .labi-doc-preview-gallery

    // RIGHT SIDE
    echo '<div class="labi-product-summary" style="flex:1; min-width:300px;">';
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('glightbox', 'https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css');
    wp_enqueue_script('glightbox', 'https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js', [], null, true);
    wp_add_inline_script('glightbox', "
        document.addEventListener('DOMContentLoaded', function() {
            GLightbox({
                selector: '.doc-lightbox',
                touchNavigation: true,
                loop: true,
                arrows: true,
                closeButton: true,
                slideEffect: 'fade'
            });
        });
    ");
});

add_action('woocommerce_product_options_general_product_data', function () {
    woocommerce_wp_select([
        'id' => '_blur_mode',
        'label' => 'Blur Mode',
        'description' => 'Choose how the document preview is blurred.',
        'options' => [
            '' => 'Izvēlieties aizmiglošanas veidu',
            'instrukcijas' => 'Instrukcijas: 1-2 lapas redzamas, 3 lapa 30% redzama',
            'riski' => 'Darba riski / vides aizsardzība: 1 lapa redzama, 2. lapa 30% redzama',
            'other' => 'Cits: 1 lapa 30% redzama',
        ]
    ]);
});

add_action('woocommerce_process_product_meta', function ($post_id) {
    if (isset($_POST['_blur_mode'])) {
        update_post_meta($post_id, '_blur_mode', sanitize_text_field($_POST['_blur_mode']));
    }
});

function labi_process_uploaded_document($post_id, $source_path, $original_filename = null) {
    $upload_dir = wp_upload_dir();
    $target_dir = $upload_dir['basedir'] . '/documents/';

    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    $filename = $original_filename ?: basename($source_path);
    $new_path = $target_dir . $filename;

    if (is_uploaded_file($source_path)) {
        move_uploaded_file($source_path, $new_path);
    } else {
        copy($source_path, $new_path);
    }

    $ext = strtolower(pathinfo($new_path, PATHINFO_EXTENSION));
    $final_pdf_path = $new_path;

    if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'pptx'])) {
        $soffice = '/home/u510781621/libreoffice/libreoffice_all/opt/libreoffice25.2/program/soffice';
        $converted_pdf = $target_dir . pathinfo($filename, PATHINFO_FILENAME) . '.pdf';

        $cmd = "$soffice --headless --convert-to pdf --outdir \"$target_dir\" \"$new_path\"";
        exec($cmd, $output, $result);
        sleep(2);

        if ($result === 0 && file_exists($converted_pdf)) {
            $final_pdf_path = $converted_pdf;
        } else {
            error_log("❌ Failed converting $new_path to PDF.");
            return;
        }
    }

    $final_url = $upload_dir['baseurl'] . '/documents/' . basename($final_pdf_path);
    update_post_meta($post_id, 'document_file_url', $final_url);

    labi_convert_pdf_to_images($post_id, $final_pdf_path);
    
    // Only set featured image if this is a downloadable product
    $product = wc_get_product($post_id);
    if (!$product || !$product->is_downloadable()) {
        return; // Skip image setting for non-downloadable products
    }

    // ✅ AUTO-SET FEATURED IMAGE — ONLY FOR DOWNLOADABLE PRODUCTS
    $product = wc_get_product($post_id);
    if (!$product || !$product->is_downloadable()) {
        return; // 🚫 Skip setting image for physical/non-downloadable products
    }

    $image_path = $upload_dir['basedir'] . "/doc_{$post_id}_page_0.jpg";

    if (file_exists($image_path)) {
        $copy_path = $upload_dir['basedir'] . "/doc_{$post_id}_page_0_copy.jpg";
        copy($image_path, $copy_path);

        $upload_file = [
            'name'     => "doc_{$post_id}_page_0.jpg",
            'type'     => 'image/jpeg',
            'tmp_name' => $copy_path,
            'error'    => 0,
            'size'     => filesize($copy_path),
        ];

        $uploaded_id = media_handle_sideload($upload_file, $post_id);
        if (!is_wp_error($uploaded_id)) {
            set_post_thumbnail($post_id, $uploaded_id);
        } else {
            error_log('❌ Failed to set featured image: ' . $uploaded_id->get_error_message());
        }
    }
    
    if (file_exists($image_path)) {
    $copy_path = $upload_dir['basedir'] . "/doc_{$post_id}_page_0_copy.jpg";
    copy($image_path, $copy_path);

    $upload_file = [
        'name'     => "doc_{$post_id}_page_0.jpg",
        'type'     => 'image/jpeg',
        'tmp_name' => $copy_path,
        'error'    => 0,
        'size'     => filesize($copy_path),
    ];

    $uploaded_id = media_handle_sideload($upload_file, $post_id);
        if (!is_wp_error($uploaded_id)) {
            set_post_thumbnail($post_id, $uploaded_id);
        } else {
            error_log('❌ Failed to set featured image: ' . $uploaded_id->get_error_message());
        }
    }
}

add_filter('woocommerce_is_sold_individually', 'labi_force_sold_individually_for_downloadables', 10, 2);

function labi_force_sold_individually_for_downloadables($sold_individually, $product) {
    if ($product instanceof WC_Product && $product->is_downloadable()) {
        return true; // Force "Sold individually" for downloadable products
    }

    return $sold_individually;
}
