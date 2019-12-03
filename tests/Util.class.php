<?php
class Util {
    
    public const KEY_ID = 'id';
    
    /**
     * Return whether or not the given $key/$value element exists in the given array
     * @param array $array
     * @param string $key
     * @param string $value
     * @return boolean
     */
    public static function inArrayByKeyValue(array $array, string $key, string $value) {
        foreach($array as $elem) {
            if ($value == $elem[$key])
                return true;
        }
        return false;
    }
    
    /**
     * Make the $sub array containing the same keys as the $ref array, and
     * fill the self::KEY_ID field in $ref if empty.
     * Keys from $sub that are not present in $ref are suppressed.
     * Function is recursive.
     * @param array $ref
     * @param array $sub
     */
    public static function alignArrayKeysAndGetId(array &$ref, array &$sub) {
        foreach($sub as $key => $val) {
            if ($key === self::KEY_ID && empty($ref[$key]) && array_key_exists($key, $ref)) {
                $ref[$key] = $val;
            }
            if (!array_key_exists($key, $ref)) {
                unset($sub[$key]);
            }
            elseif (is_array($val) && is_array($ref[$key])) {
                self::alignArrayKeysAndGetId($ref[$key], $sub[$key]);
            }
        }
    }
    
    /**
     * Copy values from the src array to the dest array.
     * Only keys defined in the src array are treated if $keys_from_src_only is true.
     * Only keys defined in the src and dest arrays are treated if $keys_from_src_only is false.
     * @param array $src source array
     * @param array $dest destination array
     * @param bool $keys_from_src_only
     */
    public static function copyValues(array $src, array &$dest, bool $keys_from_src_only=true) {
        if ($keys_from_src_only) {
            foreach($src as $key => $val) {
                if (is_array($val))
                    self::copyValues($src[$key], $dest[$key], $keys_from_src_only);
                    else
                        $dest[$key] = $src[$key];
            }
        }
        else {
            foreach($dest as $key => $val) {
                if (array_key_exists($key, $src)) {
                    if (is_array($val))
                        self::copyValues($src[$key], $dest[$key], $keys_from_src_only);
                        else
                            $dest[$key] = $src[$key];
                }
            }
        }
    }
}