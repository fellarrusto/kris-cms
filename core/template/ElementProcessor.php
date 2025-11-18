<?php

class ElementProcessor {
    private $lang;
    
    public function __construct($lang) {
        $this->lang = $lang;
    }
    
    public function process(&$html, $entity) {
        // Replace conditionals
        $html = preg_replace_callback(
            '/\{\{#if\s+(\w+)\s*(==|!=|>|<|>=|<=)\s*(.+?)\}\}(.*?)(?:\{\{#elif\s+(\w+)\s*(==|!=|>|<|>=|<=)\s*(.+?)\}\}(.*?))*(?:\{\{#else\}\}(.*?))?\{\{\/if\}\}/s',
            function($matches) use ($entity) {
                try {
                    return $this->evaluateCondition($matches, $entity);
                } catch (Exception $e) {
                    return '<span style="color:red;font-weight:bold;">ERROR: ' . htmlspecialchars($e->getMessage()) . '</span>';
                }
            },
            $html
        );
        
        // Check for unclosed conditionals
        if (preg_match('/\{\{#if\s/', $html) && !preg_match('/\{\{\/if\}\}/', $html)) {
            $html = preg_replace('/\{\{#if.*$/s', '<span style="color:red;font-weight:bold;">ERROR: Unclosed {{#if}} block</span>', $html);
        }
        
        // Replace variables
        preg_match_all('/\{\{(\w+)\}\}/', $html, $matches);
        
        foreach (array_unique($matches[1]) as $key) {
            $data = $entity->getData($key);
            
            $value = is_array($data) 
                ? ($data[$this->lang] ?? $data['en'] ?? '') 
                : ($data ?? '');
            
            $html = str_replace('{{' . $key . '}}', $value, $html);
        }
    }
    
    private function evaluateCondition($matches, $entity) {
        $field = $matches[1];
        $operator = $matches[2];
        $compareValue = trim($matches[3]);
        $ifContent = $matches[4];
        
        $fieldValue = $entity->getData($field);
        
        if ($fieldValue === null) {
            throw new Exception("Field '{$field}' not found");
        }
        
        if ($this->compare($fieldValue, $operator, $compareValue)) {
            return $ifContent;
        }
        
        $html = $matches[0];
        if (preg_match_all('/\{\{#elif\s+(\w+)\s*(==|!=|>|<|>=|<=)\s*(.+?)\}\}(.*?)(?=\{\{#elif|\{\{#else|\{\{\/if)/s', $html, $elifMatches, PREG_SET_ORDER)) {
            foreach ($elifMatches as $elif) {
                $elifField = $elif[1];
                $elifOp = $elif[2];
                $elifVal = trim($elif[3]);
                $elifContent = $elif[4];
                
                $elifFieldValue = $entity->getData($elifField);
                if ($elifFieldValue === null) {
                    throw new Exception("Field '{$elifField}' not found");
                }
                
                if ($this->compare($elifFieldValue, $elifOp, $elifVal)) {
                    return $elifContent;
                }
            }
        }
        
        if (preg_match('/\{\{#else\}\}(.*?)\{\{\/if\}\}/s', $html, $elseMatch)) {
            return $elseMatch[1];
        }
        
        return '';
    }
    
    private function compare($a, $op, $b) {
        $b = trim($b, '"\'');
        
        switch ($op) {
            case '==': return $a == $b;
            case '!=': return $a != $b;
            case '>': return $a > $b;
            case '<': return $a < $b;
            case '>=': return $a >= $b;
            case '<=': return $a <= $b;
            default: throw new Exception("Invalid operator '{$op}'");
        }
    }
}